<?php

namespace App\Http\Controllers;

use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Location;
use App\Models\Pterodactyl\Nest;
use App\Models\Pterodactyl\Node;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ServerCreationError;
use App\Settings\DiscordSettings;
use Carbon\Carbon;
use App\Settings\UserSettings;
use App\Settings\ServerSettings;
use App\Services\ServerCreationService;
use App\Settings\PterodactylSettings;
use App\Contracts\GamePanelClient;
use App\Enums\BillingPriority;
use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as FacadesRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;


class ServerController extends Controller
{
    private const CREATE_PERMISSION = 'user.server.create';
    private const UPGRADE_PERMISSION = 'user.server.upgrade';
    private const BILLING_PERIODS = [
        'hourly' => 3600,
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
        'quarterly' => 7776000,
        'half-annually' => 15552000,
        'annually' => 31104000
    ];

    private GamePanelClient $pterodactyl;
    private PterodactylSettings $pteroSettings;
    private GeneralSettings $generalSettings;
    private ServerSettings $serverSettings;
    private UserSettings $userSettings;
    private DiscordSettings $discordSettings;
    private ServerCreationService $serverCreationService;

    public function __construct(
        PterodactylSettings $pteroSettings,
        GeneralSettings $generalSettings,
        ServerSettings $serverSettings,
        UserSettings $userSettings,
        DiscordSettings $discordSettings,
        ServerCreationService $serverCreationService
    ) {
        $this->pteroSettings = $pteroSettings;
        $this->pterodactyl = app(GamePanelClient::class);
        $this->generalSettings = $generalSettings;
        $this->serverSettings = $serverSettings;
        $this->userSettings = $userSettings;
        $this->discordSettings = $discordSettings;
        $this->serverCreationService = $serverCreationService;
    }

    public function index(): \Illuminate\View\View
    {
        $servers = $this->getServersWithInfo();

        return view('servers.index')->with([
            'servers' => $servers,
            'power_controls_enabled' => $this->serverSettings->power_controls_enabled,
            'credits_display_name' => $this->generalSettings->credits_display_name,
            'pterodactyl_url' => $this->pteroSettings->panel_url,
            'phpmyadmin_url' => $this->generalSettings->phpmyadmin_url
        ]);
    }

    /**
     * Live power state for each of the caller's servers, polled by the list.
     *
     * One unreachable server must not blank out the rest, so each lookup is
     * caught on its own and simply reports nothing new.
     */
    public function states(): \Illuminate\Http\JsonResponse
    {
        $out = [];

        foreach (Auth::user()->servers as $server) {
            // A suspended or cancelled server has a state of its own that the
            // game panel knows nothing about; no call needed.
            if ($server->canBePowered()) {
                try {
                    $info = $this->pterodactyl->getServerAttributes($server->pterodactyl_id);
                    $server->state = $info['status'] ?? null;
                } catch (Exception $e) {
                    // Leave the state unknown rather than reporting it wrong.
                    $server->state = null;
                }
            }

            $out[$server->id] = $server->powerStateInfo() + ['can_power' => $server->canBePowered()];
        }

        return response()->json($out);
    }

    /**
     * Start, stop or restart a server from inside the billing panel.
     */
    public function power(Server $server, Request $request): \Illuminate\Http\JsonResponse
    {
        if ($server->user_id !== Auth::id()) {
            return response()->json(['error' => __('This is not your Server!')], 403);
        }

        $request->validate([
            'signal' => 'required|in:start,stop,restart,kill',
        ]);

        if (!$this->serverSettings->power_controls_enabled) {
            return response()->json([
                'error' => __('Power controls are turned off.'),
            ], 403);
        }

        if (!$server->canBePowered()) {
            return response()->json([
                'error' => __('This server cannot be controlled right now.'),
            ], 422);
        }

        $signal = $request->input('signal');

        // The two panels answer different parts of the same API shape, so a
        // refusal on the configured one is retried on the other when it has
        // credentials.
        $attempt = (new \App\Services\GamePanelFallback($this->pterodactyl))->attempt(
            fn ($client) => $client->powerAction($server, $signal),
            'power:' . $signal
        );

        $response = $attempt['response'];

        if ($response === null) {
            return response()->json([
                'error' => $this->powerFailureMessage(null, __('The game panel could not be reached.')),
            ], 502);
        }

        if ($response->failed()) {
            // The panel's own wording is the only thing that says what went
            // wrong, so it is kept rather than flattened into one sentence.
            $detail = $response->json('errors.0.detail')
                ?? $response->json('message')
                ?? $response->json('error')
                ?? '';

            Log::warning('Power action refused by the game panel', [
                'server_id' => $server->id,
                'identifier' => $server->identifier,
                'signal' => $signal,
                'panel' => $attempt['client']->panelName(),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'error' => $this->powerFailureMessage($response->status(), $detail),
            ], 502);
        }

        // The panel reports the transition, not the end state, so the list is
        // told what to expect until the next poll confirms it.
        $expected = match ($signal) {
            'start' => 'starting',
            'restart' => 'restarting',
            default => 'stopping',
        };

        $server->state = $expected;

        return response()->json($server->powerStateInfo() + ['can_power' => true]);
    }

    /**
     * Turn a refusal from the game panel into something the reader can act on.
     *
     * A customer gets the plain reason; an administrator also gets the status
     * and the panel's own text, because that is what identifies a misconfigured
     * key without going through the log file.
     */
    private function powerFailureMessage(?int $status, string $detail): string
    {
        $message = match ($status) {
            409 => __('The server is busy — it may still be installing, or suspended on the game panel.'),
            404 => __('The game panel cannot find this server for the configured client key.'),
            403 => __('The game panel refused: the client key may not control this server.'),
            401 => __('The game panel rejected the client key.'),
            default => __('The game panel did not accept that.'),
        };

        if (Auth::user()?->hasRole('Admin') && ($status !== null || $detail !== '')) {
            $message .= ' [' . trim(($status !== null ? 'HTTP ' . $status : '') . ' ' . $detail) . ']';
        }

        return $message;
    }

    public function create(): \Illuminate\View\View|RedirectResponse
    {
        $this->checkPermission(self::CREATE_PERMISSION);

        $validationResult = $this->validateServerCreation(app(Request::class));
        if ($validationResult) {
            return $validationResult;
        }

        return view('servers.create')->with([
            'productCount' => Product::where('disabled', false)->count(),
            'nodeCount' => Node::whereHas('products', function (Builder $builder) {
                $builder->where('disabled', false);
            })->count(),
            'nests' => Nest::whereHas('eggs', function (Builder $builder) {
                $builder->whereHas('products', function (Builder $builder) {
                    $builder->where('disabled', false);
                });
            })->get(),
            'locations' => Location::all(),
            'eggs' => Egg::whereHas('products', function (Builder $builder) {
                $builder->where('disabled', false);
            })->get(),
            'user' => Auth::user(),
            'server_creation_enabled' => $this->serverSettings->creation_enabled,
            'credits_display_name' => $this->generalSettings->credits_display_name,
            'location_description_enabled' => $this->serverSettings->location_description_enabled,
            'store_enabled' => $this->generalSettings->store_enabled
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $lockKey = 'server_create_lock_' . Auth::id();
        if (Cache::has($lockKey)) {
            return redirect()->route('servers.index')
                ->with('error', __('Please wait a moment before creating another server.'));
        }
        Cache::put($lockKey, true, 5);

        $validationResult = $this->validateServerCreation($request);
        if ($validationResult) {
            Cache::forget($lockKey);
            return $validationResult;
        }

        $request->validate([
            'name' => 'required|max:191',
            'location' => 'required|exists:locations,id',
            'egg' => 'required|exists:eggs,id',
            'product' => 'required|exists:products,id',
            'egg_variables' => 'nullable|string',
            'billing_priority' => ['nullable', new Enum(BillingPriority::class)],
        ]);

        try {
            $user = $request->user();
            $product = Product::findOrFail($request->input('product'));

            $server = $this->serverCreationService->handle($user, $product, [
                'name' => $request->input('name'),
                'egg_id' => $request->input('egg'),
                'location_id' => $request->input('location'),
                'billing_priority' => $request->input('billing_priority', $product->default_billing_priority),
                'egg_variables' => $request->input('egg_variables'),
            ]);

            Cache::forget($lockKey);

            if (!$server) {
                return redirect()->route('servers.index')
                    ->with('error', __('Server creation failed'));
            }

            return redirect()->route('servers.index')
                ->with('success', __('Server created'));
        } catch (Exception $e) {
            Cache::forget($lockKey);

            return redirect()->route('servers.index')
                ->with('error', $e->getMessage());
        }
    }

    private function validateServerCreation(Request $request): ?RedirectResponse
    {
        $user = Auth::user();

        if ($user->servers()->count() >= $user->server_limit) {
            return redirect()->route('servers.index')
                ->with('error', __('Server limit reached!'));
        }

        if ($request->has('product')) {
            $product = Product::findOrFail($request->input('product'));

            $validationResult = $this->validateProductRequirements($product, $request);
            if ($validationResult !== true) {
                return redirect()->route('servers.index')
                    ->with('error', $validationResult);
            }
        }

        if (!$this->validateUserRequirements()) {
            return redirect()->route('profile.index')
                ->with('error', __('User requirements not met'));
        }

        return null;
    }

    private function validateProductRequirements(Product $product, Request $request): string|bool
    {
        $location = $request->input('location');
        $nodeAllocation = $this->findAvailableNodeWithAllocation($location, $product);

        if (!$nodeAllocation) {
            return __("The selected location does not have the required memory, disk, or is overloaded.");
        }

        $user = Auth::user();
        $productCount = $user->servers()->where("product_id", $product->id)->count();
        if ($productCount >= $product->serverlimit && $product->serverlimit != 0) {
            return __('You can not create any more Servers with this product!');
        }

        // Determine effective minimum credits; fallback to price when the stored
        // value is missing or nonsensical (e.g. a legacy -1 entry).
        $minCredits = ($product->minimum_credits === null || $product->minimum_credits < $product->price)
            ? $product->price
            : $product->minimum_credits;

        if ($user->credits < $minCredits) {
            return __('You do not have the required amount of :credits to use this product!', [
                'credits' => $this->generalSettings->credits_display_name,
            ]);
        }

        return true;
    }

    private function validateUserRequirements(): bool
    {
        $user = Auth::user();

        if ($this->userSettings->force_email_verification && !$user->hasVerifiedEmail()) {
            return false;
        }

        if (!$this->serverSettings->creation_enabled && $user->cannot("admin.servers.bypass_creation_enabled")) {
            return false;
        }

        if ($this->userSettings->force_discord_verification && !$user->discordUser) {
            return false;
        }

        return true;
    }

    private function getServersWithInfo(): \Illuminate\Database\Eloquent\Collection
    {
        $servers = Auth::user()->servers;

        foreach ($servers as $server) {
            try {
                $serverInfo = $this->pterodactyl->getServerAttributes($server->pterodactyl_id);
            } catch (Exception $e) {
                // One server the panel cannot answer for must not take the
                // whole list down; it is listed with what is stored locally.
                Log::warning('Could not read server from the game panel', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (!$serverInfo) {
                continue;
            }

            $this->updateServerInfo($server, $serverInfo);
        }

        return $servers;
    }

    private function updateServerInfo(Server $server, array $serverInfo): void
    {
        try {
            $relationships = $serverInfo['relationships'] ?? [];
            $locationAttrs = $relationships['location']['attributes'] ?? [];
            $eggAttrs = $relationships['egg']['attributes'] ?? [];
            $nestAttrs = $relationships['nest']['attributes'] ?? [];
            $nodeAttrs = $relationships['node']['attributes'] ?? [];

            $server->location = $locationAttrs['long'] ?? $locationAttrs['short'] ?? null;
            $server->egg = $eggAttrs['name'] ?? null;
            $server->nest = $nestAttrs['name'] ?? null;
            $server->node = $nodeAttrs['name'] ?? null;

            // Not every panel embeds the related records; the synced tables hold
            // the same names, so they are used as the fallback.
            if (!$server->node && isset($serverInfo['node'])) {
                $node = \App\Models\Pterodactyl\Node::find($serverInfo['node']);
                $server->node = $node?->name;
                $server->location = $server->location
                    ?: \App\Models\Pterodactyl\Location::find($node?->location_id)?->name;
            }

            if (!$server->egg && isset($serverInfo['egg'])) {
                $server->egg = \App\Models\Pterodactyl\Egg::find($serverInfo['egg'])?->name;
            }

            if (!$server->nest && isset($serverInfo['nest'])) {
                $server->nest = \App\Models\Pterodactyl\Nest::find($serverInfo['nest'])?->name;
            }

            // Connection address and the live power state, both optional.
            $allocation = $relationships['allocations']['data'][0]['attributes'] ?? null;

            if ($allocation) {
                $host = $allocation['alias'] ?: ($allocation['ip'] ?? null);
                $server->address = $host && isset($allocation['port'])
                    ? $host . ':' . $allocation['port']
                    : null;
            }

            $server->state = $serverInfo['status'] ?? null;

            if (isset($serverInfo['name']) && $server->name !== $serverInfo['name']) {
                $server->name = $serverInfo['name'];
                $server->save();
            }

            if ($server->product_id) {
                $server->setRelation('product', Product::find($server->product_id));
            }
        } catch (Exception $e) {
            Log::error('Failed to update server info', [
                'server_id' => $server->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * @deprecated Once everyone is migrated to ServerCreationService.
     */
    private function createServer(Request $request): ?Server
    {
        // Legacy path; use ServerCreationService::handle() instead.
        throw new \BadMethodCallException('createServer() is deprecated. Use ServerCreationService::handle().');
    }

    /**
     * @deprecated Once everyone is migrated to ServerCreationService.
     */
    private function handlePostCreation(User $user, Server $server): void
    {
        // Legacy path; use PostServerCreationJob for idempotent async processing.
        throw new \BadMethodCallException('handlePostCreation() is deprecated. Use PostServerCreationJob instead.');
    }

    public function destroy(Server $server, Request $request)
    {
        if ($server->user_id !== Auth::id()) {
            return $this->serverActionResult($request, false, __('This is not your Server!'));
        }

        try {
            // Reading the server back first only tells us the panel is awake;
            // the delete itself reports whether it worked, and a server the
            // panel has already lost must still be removable from here.
            $this->handleServerDeletion($server);

            return $this->serverActionResult($request, true, __('Server removed'));
        } catch (Exception $e) {
            Log::error('Server deletion failed', [
                'server_id' => $server->id,
                'pterodactyl_id' => $server->pterodactyl_id,
                'error' => $e->getMessage()
            ]);

            return $this->serverActionResult(
                $request,
                false,
                __('Server removal failed: ') . $e->getMessage()
            );
        }
    }

    /**
     * The list calls these over fetch, which repeats a non-GET method when it
     * follows a redirect — a DELETE answered with one came back as a DELETE on
     * `/servers` and failed a second time, hiding the real reason. A request
     * that asks for JSON gets JSON.
     */
    private function serverActionResult(Request $request, bool $ok, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(
                $ok ? ['message' => $message] : ['error' => $message],
                $ok ? 200 : 422
            );
        }

        return redirect()->route('servers.index')
            ->with($ok ? 'success' : 'error', $message);
    }

    private function handleServerDeletion(Server $server): void
    {
        if ($this->discordSettings->role_for_active_clients) {
            $user = User::findOrFail($server->user_id);
            if ($user->discordUser && $user->servers->count() <= 1) {
                $user->discordUser->addOrRemoveRole(
                    'remove',
                    $this->discordSettings->role_id_for_active_clients
                );
            }
        }

        $server->delete();
        Cache::forget('user_credits_left:' . $server->user_id);
    }

    public function cancel(Server $server, Request $request)
    {
        if ($server->user_id !== Auth::id()) {
            return $this->serverActionResult($request, false, __('This is not your Server!'));
        }

        try {
            $server->update(['canceled' => now()]);

            return $this->serverActionResult($request, true, __('Server canceled'));
        } catch (Exception $e) {
            return $this->serverActionResult(
                $request,
                false,
                __('Server cancellation failed: ') . $e->getMessage()
            );
        }
    }

    public function show(Server $server): \Illuminate\View\View
    {
        if ($server->user_id !== Auth::id()) {
            return back()->with('error', __('This is not your Server!'));
        }

        $serverAttributes = $this->pterodactyl->getServerAttributes($server->pterodactyl_id);
        $upgradeOptions = $this->getUpgradeOptions($server, $serverAttributes);
        return view('servers.settings')->with([
            'server' => $server,
            'serverAttributes' => $serverAttributes,
            'products' => $upgradeOptions,
            'server_enable_upgrade' => $this->serverSettings->enable_upgrade,
            'credits_display_name' => $this->generalSettings->credits_display_name,
            'location_description_enabled' => $this->serverSettings->location_description_enabled,
        ]);
    }

    private function getUpgradeOptions(Server $server, array $serverInfo): \Illuminate\Database\Eloquent\Collection
    {
        $currentProduct = Product::find($server->product_id);
        $nodeId = $serverInfo['relationships']['node']['attributes']['id'];
        // Capacity, not the raw node: null when the panel does not report it.
        $pteroNode = $this->pterodactyl->getNodeCapacity($nodeId);
        $currentEgg = $serverInfo['egg'];

        //$currentProductEggs = $currentProduct->eggs->pluck('id')->toArray();

        return Product::orderBy('price', 'asc')
            ->with('nodes')->with('eggs')
            ->whereHas('nodes', function (Builder $builder) use ($nodeId) {
                $builder->where('id', $nodeId);
            })
            ->whereHas('eggs', function (Builder $builder) use ($currentEgg) {
                $builder->where('id', $currentEgg);
            })
            ->get()
            ->map(function ($product) use ($currentProduct, $pteroNode) {
                $product->eggs = $product->eggs->pluck('name')->toArray();

                $memoryDiff = $product->memory - $currentProduct->memory;
                $diskDiff = $product->disk - $currentProduct->disk;

                // $pteroNode is null when the panel does not report capacity;
                // an unknown limit must not be treated as "does not fit".
                if ($pteroNode !== null) {
                    if ($memoryDiff > $pteroNode['memory'] - $pteroNode['used_memory'] ||
                        $diskDiff > $pteroNode['disk'] - $pteroNode['used_disk']) {
                        $product->doesNotFit = true;
                    }
                }

                return $product;
            });
    }


    public function upgrade(Server $server, Request $request): RedirectResponse
    {
        $this->checkPermission(self::UPGRADE_PERMISSION);

        if ($server->user_id !== Auth::id()) {
            return redirect()->route('servers.index')
                ->with('error', __('This is not your Server!'));
        }

        if (!$request->has('product_upgrade')) {
            return redirect()->route('servers.show', ['server' => $server->id])
                ->with('error', __('No product selected for upgrade'));
        }

        $user = Auth::user();
        $oldProduct = Product::find($server->product->id);
        $newProduct = Product::find($request->product_upgrade);

        if (!$newProduct) {
            return redirect()->route('servers.show', ['server' => $server->id])
                ->with('error', __('Selected product not found'));
        }

        if (!$this->validateUpgrade($server, $oldProduct, $newProduct)) {
            return redirect()->route('servers.show', ['server' => $server->id])
                ->with('error', __('Insufficient resources or credits for upgrade'));
        }

        try {
            $this->processUpgrade($server, $oldProduct, $newProduct, $user);
            return redirect()->route('servers.show', ['server' => $server->id])
                ->with('success', __('Server Successfully Upgraded'));
        } catch (Exception $e) {
            Log::error('Server upgrade failed', [
                'server_id' => $server->id,
                'old_product' => $oldProduct->id,
                'new_product' => $newProduct->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('servers.show', ['server' => $server->id])
                ->with('error', __('Upgrade failed: ') . $e->getMessage());
        }
    }

    public function updateBillingPriority(Server $server, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'billing_priority' => ['required', new Enum(BillingPriority::class)],
        ]);

        if ($server->user_id !== Auth::id()) {
            return redirect()->route('servers.index')
                ->with('error', __('This is not your Server!'));
        }

        $server->update($data);

        return redirect()->route('servers.show', ['server' => $server->id])
            ->with('success', __('Billing priority updated successfully'));
    }

    private function validateUpgrade(Server $server, Product $oldProduct, Product $newProduct): bool
    {
        $user = Auth::user();
        if (!$server->product) {
            return false;
        }

        $serverInfo = $this->pterodactyl->getServerAttributes($server->pterodactyl_id);
        if (!$serverInfo) {
            return false;
        }

        $nodeId = $serverInfo['relationships']['node']['attributes']['id'];
        $node = Node::findOrFail($nodeId);

        // Check node resources
        $requireMemory = $newProduct->memory - $oldProduct->memory;
        $requireDisk = $newProduct->disk - $oldProduct->disk;
        if (!$this->pterodactyl->checkNodeResources($node, $requireMemory, $requireDisk)) {
            return false;
        }

        // Check if user has enough credits after refund
        $refundAmount = $this->calculateRefund($server, $oldProduct);
        if ($user->credits < ($newProduct->price - $refundAmount)) {
            return false;
        }

        return true;
    }

    private function processUpgrade(Server $server, Product $oldProduct, Product $newProduct, User $user): void
    {
        $server->allocation = $this->pterodactyl->getServerAttributes($server->pterodactyl_id)['allocation'];

        $response = $this->pterodactyl->updateServer($server, $newProduct);
        if ($response->failed()) {
            throw new Exception("Failed to update server on Pterodactyl");
        }

        $restartResponse = $this->pterodactyl->powerAction($server, 'restart');
        if ($restartResponse->failed()) {
            throw new Exception('Could not restart the server: ' . $restartResponse->json()['errors'][0]['detail']);
        }

        // Calculate refund
        $refund = $this->calculateRefund($server, $oldProduct);
        if ($refund > 0) {
            $user->increment('credits', $refund);
        }

        // Update server
        unset($server->allocation);
        $server->update([
            'product_id' => $newProduct->id,
            'updated_at' => now(),
            'last_billed' => now(),
            'canceled' => null,
        ]);

        // Charge for new product
        $user->decrement('credits', $newProduct->price);
    }

    private function calculateRefund(Server $server, Product $oldProduct): float
    {
        $billingPeriod = $oldProduct->billing_period;
        $billingPeriodSeconds = self::BILLING_PERIODS[$billingPeriod];
        $timeUsed = now()->diffInSeconds($server->last_billed, true);

        return $oldProduct->price - ($oldProduct->price * ($timeUsed / $billingPeriodSeconds));
    }

    private function findAvailableNode(string $locationId, Product $product): ?Node
    {
        $nodes = Node::where('location_id', $locationId)
            ->whereHas('products', fn($q) => $q->where('product_id', $product->id))
            ->get();

        $availableNodes = $nodes->reject(function ($node) use ($product) {
            // Check if node has enough memory and disk resources
            if (!$this->pterodactyl->checkNodeResources($node, $product->memory, $product->disk)) {
                return true;
            }

            // Check if node has free allocations (IP/port)
            $freeAllocations = $this->pterodactyl->getFreeAllocations($node);
            return empty($freeAllocations);
        });

        return $availableNodes->isEmpty() ? null : $availableNodes->first();
    }

    /**
     * Find a node in the given location for the product that has required resources
     * and also a free allocation on Pterodactyl. Returns ['node' => Node, 'allocation_id' => int]
     * or null when none available.
     */
    private function findAvailableNodeWithAllocation(string $locationId, Product $product): ?array
    {
        $nodes = Node::where('location_id', $locationId)
            ->whereHas('products', fn($q) => $q->where('product_id', $product->id))
            ->get();

        $availableNodes = $nodes->reject(function ($node) use ($product) {
            return !$this->pterodactyl->checkNodeResources($node, $product->memory, $product->disk);
        });

        foreach ($availableNodes as $node) {
            try {
                $allocationId = $this->pterodactyl->getFreeAllocationId($node);
            } catch (\Exception $e) {
                Log::warning('Failed to get allocation for node while searching for free allocation', [
                    'node_id' => $node->id,
                    'exception' => $e->getMessage(),
                ]);
                $allocationId = null;
            }

            if ($allocationId) {
                return ['node' => $node, 'allocation_id' => $allocationId];
            }
        }

        return null;
    }

    public function validateDeploymentVariables(Request $request)
    {
        $variables = $request->input('variables');

        $errors = [];

        foreach ($variables as $variable) {
            $rules = $variable['rules'];
            $envVariable = $variable['env_variable'];
            $filledValue = $variable['filled_value'];

            $validator = Validator::make(
                [$envVariable => $filledValue],
                [$envVariable => $rules]
            );

            $validator->setAttributeNames([
                $envVariable => $variable['name'],
            ]);

            if ($validator->fails()) {
                $errors[$envVariable] = $validator->errors()->get($envVariable);
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'errors' => $errors
            ], 422);
        }

        return response()->json([
            'success' => true,
            'variables' => $variables,
        ]);
    }
}
