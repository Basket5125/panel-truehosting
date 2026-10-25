<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Pterodactyl\Egg;
use App\Models\Server;
use App\Services\TrialService;
use App\Settings\GeneralSettings;
use Coderflex\LaravelTurnstile\Rules\TurnstileCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The free trial: a two step wizard to create one, and the two things that
 * can happen to it afterwards — extend it, or buy it.
 */
class TrialServerController extends Controller
{
    public function __construct(
        private TrialService $trials,
        private GeneralSettings $generalSettings,
    ) {
        $this->middleware('auth');
    }

    public function create()
    {
        abort_unless($this->trials->offered(), 404);

        $products = $this->trials->products();

        return view('trial.create', [
            'blockedReason' => $this->trials->blockedReason(Auth::user()),
            'blockedKind' => $this->trials->blockedKind(Auth::user()),
            'products' => $products,
            // Shipped to the wizard as data so step two can react without a
            // round trip. Only user-facing variables are exposed.
            'catalogue' => $products->mapWithKeys(fn (Product $product) => [
                $product->id => [
                    'name' => $product->name,
                    'eggs' => $this->trials->eggsFor($product)->map(fn (Egg $egg) => [
                        'id' => $egg->id,
                        'name' => ($egg->nest?->name ? $egg->nest->name . ' · ' : '') . $egg->name,
                        'variables' => $this->customerVariables($egg),
                    ])->values(),
                ],
            ]),
            'durationHours' => $this->trials->durationHours(),
            'maxExtensions' => $this->trials->maxExtensions(),
            'endAction' => $this->trials->endAction(),
            'requiresCaptcha' => $this->trials->requiresCaptcha(),
            'recaptchaVersion' => $this->generalSettings->recaptcha_version,
            'credits_display_name' => $this->generalSettings->credits_display_name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->trials->offered(), 404);

        $rules = [
            'name' => 'required|string|max:191',
            'product' => 'required|string',
            'egg' => 'required|integer',
            'variables' => 'nullable|array',
        ];

        // The captcha only applies when one is actually configured.
        if ($this->trials->requiresCaptcha() && $this->generalSettings->recaptcha_version) {
            $rules = array_merge($rules, match ($this->generalSettings->recaptcha_version) {
                'v2' => ['g-recaptcha-response' => ['required', 'recaptcha']],
                'v3' => ['g-recaptcha-response' => ['required', 'recaptchav3:recaptchathree,0.5']],
                'turnstile' => ['cf-turnstile-response' => ['required', new TurnstileCheck()]],
                default => [],
            });
        }

        $request->validate($rules);

        try {
            $egg = $this->resolveEgg($request);
            $variables = $this->validatedVariables($egg, (array) $request->input('variables', []));

            $server = $this->trials->create(Auth::user(), [
                'name' => $request->input('name'),
                'product' => $request->input('product'),
                'egg' => $egg->id,
                'egg_variables' => $variables === [] ? null : json_encode($variables),
            ]);
        } catch (Throwable $e) {
            Log::warning('Trial server creation failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('servers.index')
            // Picked up once by the servers list, which then walks the
            // customer through what to do with it.
            ->with('trial_created', $server->id)
            ->with('success', __('Your trial server is being created.'));
    }

    public function purchase(Server $server, Request $request): JsonResponse
    {
        try {
            $this->trials->purchase($server, $request->user());
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => __('The server is yours.')]);
    }

    public function extend(Server $server, Request $request): JsonResponse
    {
        try {
            $this->trials->extend($server, $request->user());
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Trial extended.'),
            'trial_ends_at' => $server->fresh()->trial_ends_at?->toIso8601String(),
        ]);
    }

    /**
     * The egg the request asked for, confirmed against the trial offer rather
     * than taken from the request.
     *
     * @throws \Exception
     */
    private function resolveEgg(Request $request): Egg
    {
        $product = $this->trials->products()->firstWhere('id', $request->input('product'));

        if (!$product) {
            throw new \Exception(__('That package is not part of the trial offer.'));
        }

        $egg = $this->trials->eggsFor($product)->firstWhere('id', (int) $request->input('egg'));

        if (!$egg) {
            throw new \Exception(__('That software is not part of the trial offer.'));
        }

        return $egg;
    }

    /**
     * The variables a customer is allowed to see and set for this egg.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customerVariables(Egg $egg): array
    {
        return collect((array) $egg->environment)
            ->filter(fn ($variable) => ($variable['user_viewable'] ?? false) && ($variable['user_editable'] ?? false))
            ->map(fn ($variable) => [
                'name' => $variable['name'] ?? $variable['env_variable'],
                'env_variable' => $variable['env_variable'],
                'description' => $variable['description'] ?? '',
                'default_value' => (string) ($variable['default_value'] ?? ''),
                'required' => str_contains((string) ($variable['rules'] ?? ''), 'required'),
                // One of a fixed set, when the egg says so, so the wizard can
                // offer a list instead of a free text box.
                'options' => $this->ruleOptions((string) ($variable['rules'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * Validate submitted variables against the rules stored with the egg.
     *
     * The rules come from our own copy of the egg and never from the request:
     * a crafted payload must not be able to relax its own validation, and a
     * variable the egg does not expose to customers is dropped outright.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validatedVariables(Egg $egg, array $submitted): array
    {
        $allowed = collect((array) $egg->environment)
            ->filter(fn ($variable) => ($variable['user_viewable'] ?? false) && ($variable['user_editable'] ?? false))
            ->keyBy('env_variable');

        $values = [];
        $rules = [];
        $names = [];

        foreach ($allowed as $key => $variable) {
            $values[$key] = array_key_exists($key, $submitted)
                ? (string) $submitted[$key]
                : (string) ($variable['default_value'] ?? '');
            $rules[$key] = $variable['rules'] ?? 'nullable';
            $names[$key] = $variable['name'] ?? $key;
        }

        if ($values === []) {
            return [];
        }

        $validator = Validator::make($values, $rules);
        $validator->setAttributeNames($names);
        $validator->validate();

        return $values;
    }

    /**
     * Values an `in:` rule allows, if the rule has one.
     *
     * @return array<int, string>
     */
    private function ruleOptions(string $rules): array
    {
        if (!preg_match('/(?:^|\|)in:([^|]+)/', $rules, $match)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $match[1]))));
    }
}
