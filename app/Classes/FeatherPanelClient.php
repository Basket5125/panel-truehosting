<?php

namespace App\Classes;

use App\Classes\PanelServerShape;
use App\Contracts\GamePanelClient;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Nest;
use App\Models\Pterodactyl\Node;
use App\Models\Product;
use App\Models\Server;
use App\Settings\FeatherPanelSettings;
use App\Settings\ServerSettings;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Talks to FeatherPanel and hands back Pterodactyl-shaped payloads.
 *
 * The rest of the application (models, controllers, views) was written against
 * Pterodactyl's `{"attributes": {...}}` envelope and its field names, so every
 * response is translated here instead of leaking FeatherPanel's own shape.
 *
 * Naming map: realm = nest, spell = egg.
 */
class FeatherPanelClient implements GamePanelClient
{
    private int $per_page_limit = 50;

    private int $allocation_limit = 200;

    private string $base_url = '';

    public PendingRequest $client;

    public PendingRequest $application;

    public function __construct(FeatherPanelSettings $settings)
    {
        try {
            $this->base_url = $settings->getUrl();
            $this->application = $this->makeClient($settings, $settings->admin_token);
            // The user key is optional; the admin key works for client routes too.
            $this->client = $this->makeClient($settings, $settings->user_token ?: $settings->admin_token);
            $this->per_page_limit = $settings->per_page_limit;
            $this->allocation_limit = (new ServerSettings())->allocation_limit;
        } catch (Exception $exception) {
            logger('Failed to construct FeatherPanel client, Settings table not available?', ['exception' => $exception]);
        }
    }

    private function makeClient(FeatherPanelSettings $settings, string $token): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->baseUrl($settings->getUrl() . 'api/');
    }

    public function panelName(): string
    {
        return 'FeatherPanel';
    }

    public function panelUrl(): string
    {
        return rtrim($this->base_url, '/');
    }

    /* ---------------------------------------------------------------------
     | Plumbing
     * ------------------------------------------------------------------- */

    private function getException(string $message = '', ?int $status = null): HttpException|Exception
    {
        Log::error('FeatherPanelClient: ' . $message);

        return match (true) {
            $status === 404 => new HttpException(404, 'Resource does not exist on FeatherPanel - ' . $message . ' Was a server deleted on the panel but not here?'),
            $status === 403 => new HttpException(403, 'No permission on FeatherPanel, check the API key permission nodes - ' . $message),
            $status === 401 => new HttpException(401, 'FeatherPanel rejected the API key - ' . $message),
            $status === 0 => new HttpException(500, 'Unable to reach FeatherPanel - is the panel online and the URL correct? ' . $message),
            $status !== null && $status >= 500 => new HttpException($status, 'FeatherPanel server error (HTTP ' . $status . ') - ' . $message),
            default => new Exception('Request failed, is FeatherPanel set up correctly? - ' . $message),
        };
    }

    /**
     * Unwrap the {success, data, error_message} envelope and fail loudly.
     *
     * @throws Exception
     */
    private function unwrap(Response $response, string $what): array
    {
        if ($response->failed()) {
            $body = $response->json();
            $message = $body['error_message'] ?? $body['message'] ?? $response->body();

            throw $this->getException($what . ' - ' . $message, $response->status());
        }

        $body = $response->json();

        if (is_array($body) && array_key_exists('success', $body) && $body['success'] === false) {
            throw $this->getException($what . ' - ' . ($body['error_message'] ?? $body['message'] ?? 'unknown error'));
        }

        $data = $body['data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    /**
     * Walk a paginated admin collection and return every record.
     *
     * @return array<int, array>
     *
     * @throws Exception
     */
    private function collect(string $path, string $key, array $query = [], ?int $hardLimit = null): array
    {
        $out = [];
        $page = 1;
        $limit = $hardLimit ?: $this->per_page_limit;

        do {
            try {
                $response = $this->application->get($path, array_merge($query, [
                    'page' => $page,
                    'limit' => $limit,
                ]));
            } catch (Exception $e) {
                throw $this->getException('Failed to list ' . $key . ' - ' . $e->getMessage());
            }

            $data = $this->unwrap($response, 'Failed to list ' . $key);
            $rows = $data[$key] ?? [];
            $out = array_merge($out, $rows);

            $pagination = $data['pagination'] ?? [];
            $hasNext = $pagination['has_next'] ?? false;
            $page++;

            // Guard against a panel that never reports the last page.
            if ($page > 200) {
                break;
            }
        } while ($hasNext && count($rows) > 0);

        return $out;
    }

    /**
     * Pterodactyl wrapped every record; the callers still expect that.
     *
     * @param  array<int, array>  $records
     * @return array<int, array{attributes: array}>
     */
    private function wrap(array $records, callable $mapper): array
    {
        return array_values(array_map(fn ($record) => ['attributes' => $mapper($record)], $records));
    }

    /* ---------------------------------------------------------------------
     | Translation
     * ------------------------------------------------------------------- */

    private function mapLocation(array $l): array
    {
        return [
            'id' => $l['id'] ?? null,
            // Pterodactyl called these short/long and the sync still reads them.
            'short' => $l['name'] ?? '',
            'long' => $l['description'] ?? '',
            'name' => $l['name'] ?? '',
            'description' => $l['description'] ?? '',
        ];
    }

    private function mapRealm(array $r): array
    {
        return [
            'id' => $r['id'] ?? null,
            'name' => $r['name'] ?? '',
            'description' => $r['description'] ?? '',
        ];
    }

    private function mapNode(array $n): array
    {
        return [
            'id' => $n['id'] ?? null,
            'location_id' => $n['location_id'] ?? null,
            'name' => $n['name'] ?? '',
            'description' => $n['description'] ?? ($n['fqdn'] ?? ''),
            'fqdn' => $n['fqdn'] ?? '',
            'uuid' => $n['uuid'] ?? null,
        ];
    }

    private function mapAllocation(array $a): array
    {
        return [
            'id' => $a['id'] ?? null,
            'ip' => $a['ip'] ?? '',
            'alias' => $a['ip_alias'] ?? null,
            'port' => $a['port'] ?? null,
            'notes' => $a['notes'] ?? null,
            'assigned' => !empty($a['server_id']),
            'server_id' => $a['server_id'] ?? null,
        ];
    }

    /**
     * @param  array<int, array>  $variables
     */
    private function mapSpell(array $s, array $variables = []): array
    {
        return [
            'id' => $s['id'] ?? null,
            // The sync reads `nest`; a realm is the same concept.
            'nest' => $s['realm_id'] ?? null,
            'realm_id' => $s['realm_id'] ?? null,
            'name' => $s['name'] ?? '',
            'description' => $s['description'] ?? '',
            'docker_image' => $this->resolveDockerImage($s),
            'startup' => $s['startup'] ?? '',
            'relationships' => [
                'variables' => [
                    'data' => array_map(fn ($v) => ['attributes' => [
                        'name' => $v['name'] ?? '',
                        'description' => $v['description'] ?? '',
                        'default_value' => $v['default_value'] ?? '',
                        'env_variable' => $v['env_variable'] ?? '',
                        'user_viewable' => (bool) ($v['user_viewable'] ?? true),
                        'user_editable' => (bool) ($v['user_editable'] ?? true),
                        'rules' => $v['rules'] ?? '',
                    ]], $variables),
                ],
            ],
        ];
    }

    /**
     * One rule for both panels; see Egg::resolveDockerImage().
     */
    private function resolveDockerImage(array $spell): string
    {
        return Egg::resolveDockerImage($spell);
    }

    private function mapServer(array $s): array
    {
        return [
            'id' => $s['id'] ?? null,
            'external_id' => $s['external_id'] ?? null,
            'uuid' => $s['uuid'] ?? null,
            // Pterodactyl's "identifier" is FeatherPanel's short uuid.
            'identifier' => $s['uuidShort'] ?? ($s['uuid'] ?? null),
            'name' => $s['name'] ?? '',
            'description' => $s['description'] ?? '',
            'suspended' => (bool) ($s['suspended'] ?? false),
            'status' => $s['status'] ?? null,
            'user' => $s['owner_id'] ?? null,
            'node' => $s['node_id'] ?? null,
            'nest' => $s['realms_id'] ?? null,
            'egg' => $s['spell_id'] ?? null,
            'allocation' => $s['allocation_id'] ?? null,
            'limits' => [
                'memory' => $s['memory'] ?? 0,
                'swap' => $s['swap'] ?? 0,
                'disk' => $s['disk'] ?? 0,
                'io' => $s['io'] ?? 500,
                'cpu' => $s['cpu'] ?? 0,
                'oom_disabled' => !($s['oom_killer'] ?? false),
            ],
            'feature_limits' => [
                'databases' => $s['database_limit'] ?? 0,
                'backups' => $s['backup_limit'] ?? 0,
                'allocations' => $s['allocation_limit'] ?? 0,
            ],
        ];
    }

    private function mapUser(array $u): array
    {
        return [
            'id' => $u['id'] ?? null,
            'uuid' => $u['uuid'] ?? null,
            'external_id' => $u['external_id'] ?? null,
            'username' => $u['username'] ?? '',
            'email' => $u['email'] ?? '',
            'first_name' => $u['first_name'] ?? ($u['username'] ?? ''),
            'last_name' => $u['last_name'] ?? ($u['username'] ?? ''),
            'root_admin' => (bool) ($u['root_admin'] ?? false),
        ];
    }

    /**
     * FeatherPanel addresses users by uuid, while CtrlPanel stores the numeric
     * id. The numeric filter on the list endpoint bridges the two without
     * needing a new column.
     *
     * @throws Exception
     */
    private function resolveUserUuid(int $panelUserId): ?string
    {
        $rows = $this->collect('admin/users', 'users', ['user_id' => $panelUserId], 1);

        return $rows[0]['uuid'] ?? null;
    }

    /* ---------------------------------------------------------------------
     | Catalogue
     * ------------------------------------------------------------------- */

    public function getLocations()
    {
        return $this->wrap($this->collect('admin/locations', 'locations'), fn ($l) => $this->mapLocation($l));
    }

    public function getNests()
    {
        return $this->wrap($this->collect('admin/realms', 'realms'), fn ($r) => $this->mapRealm($r));
    }

    public function getNodes()
    {
        return $this->wrap($this->collect('admin/nodes', 'nodes'), fn ($n) => $this->mapNode($n));
    }

    public function getNode($id)
    {
        try {
            $response = $this->application->get("admin/nodes/{$id}");
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }

        $data = $this->unwrap($response, 'Failed to get node ' . $id);

        return $this->mapNode($data['node'] ?? $data);
    }

    public function getEggs(Nest $nest)
    {
        $spells = $this->collect("admin/spells/realm/{$nest->id}", 'spells');

        $out = [];
        foreach ($spells as $spell) {
            $out[] = ['attributes' => $this->mapSpell($spell, $this->getSpellVariables($spell['id'] ?? 0))];
        }

        return $out;
    }

    /**
     * @return array<int, array>
     *
     * @throws Exception
     */
    private function getSpellVariables(int $spellId): array
    {
        if ($spellId <= 0) {
            return [];
        }

        try {
            $response = $this->application->get("admin/spells/{$spellId}/variables");
        } catch (Exception $e) {
            throw $this->getException('Failed to get spell variables - ' . $e->getMessage());
        }

        $data = $this->unwrap($response, 'Failed to get variables of spell ' . $spellId);

        return $data['variables'] ?? [];
    }

    public function getServers()
    {
        return $this->wrap($this->collect('admin/servers', 'servers'), fn ($s) => $this->mapServer($s));
    }

    /* ---------------------------------------------------------------------
     | Allocations
     * ------------------------------------------------------------------- */

    public function getAllocations(Node $node)
    {
        return $this->wrap(
            $this->collect('admin/allocations', 'allocations', ['node_id' => $node->id], $this->allocation_limit),
            fn ($a) => $this->mapAllocation($a)
        );
    }

    public function getFreeAllocations(Node $node)
    {
        return $this->wrap(
            $this->collect('admin/allocations/available', 'allocations', ['node_id' => $node->id], $this->allocation_limit),
            fn ($a) => $this->mapAllocation($a)
        );
    }

    public function getFreeAllocationId(Node $node)
    {
        $free = $this->getFreeAllocations($node);

        return $free[0]['attributes']['id'] ?? null;
    }

    /* ---------------------------------------------------------------------
     | Servers
     * ------------------------------------------------------------------- */

    public function createServer(Server $server, Egg $egg, int $allocationId, mixed $eggVariables = null)
    {
        // FeatherPanel wants the node explicitly, while Pterodactyl inferred it
        // from the allocation. The signature is shared by both drivers, so the
        // node is resolved from the allocation instead of widening it.
        $nodeId = $this->getAllocationNodeId($allocationId);

        return $this->application->put('admin/servers', [
            'name' => $server->name,
            'external_id' => $server->id,
            'owner_id' => $server->user->pterodactyl_id,
            'node_id' => $nodeId,
            'realms_id' => $egg->nest_id,
            'spell_id' => $egg->id,
            'allocation_id' => $allocationId,
            'image' => $egg->docker_image,
            'startup' => $egg->startup,
            'variables' => $this->getEnvironmentVariables($egg, $eggVariables),
            'oom_disabled' => !$server->product->oom_killer,
            'oom_killer' => (bool) $server->product->oom_killer,
            'memory' => $server->product->memory,
            'swap' => $server->product->swap,
            'disk' => $server->product->disk,
            'io' => $server->product->io,
            'cpu' => $server->product->cpu,
            'database_limit' => $server->product->databases,
            'backup_limit' => $server->product->backups,
            'allocation_limit' => $server->product->allocations,
        ]);
    }

    /**
     * @throws Exception
     */
    private function getAllocationNodeId(int $allocationId): ?int
    {
        try {
            $response = $this->application->get("admin/allocations/{$allocationId}");
        } catch (Exception $e) {
            throw $this->getException('Failed to resolve the allocation node - ' . $e->getMessage());
        }

        $data = $this->unwrap($response, 'Failed to resolve the allocation node');
        $allocation = $data['allocation'] ?? $data;

        return isset($allocation['node_id']) ? (int) $allocation['node_id'] : null;
    }

    public function getServerByExternalId(string $externalId)
    {
        try {
            return $this->application->get("admin/servers/external/{$externalId}");
        } catch (Exception $e) {
            throw $this->getException('Failed to get server by external_id - ' . $e->getMessage());
        }
    }

    public function getServerById(int $panelServerId)
    {
        try {
            return $this->application->get("admin/servers/{$panelServerId}");
        } catch (Exception $e) {
            throw $this->getException('Failed to get server - ' . $e->getMessage());
        }
    }

    public function deleteServer(int $panelServerId)
    {
        try {
            return $this->application->delete("admin/servers/{$panelServerId}");
        } catch (Exception $e) {
            throw $this->getException('Failed to delete server - ' . $e->getMessage());
        }
    }

    public function suspendServer(Server $server)
    {
        try {
            $response = $this->application->post("admin/servers/{$server->pterodactyl_id}/suspend");
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }

        if ($response->failed()) {
            throw $this->getException('Failed to suspend server', $response->status());
        }

        return $response;
    }

    public function unSuspendServer(Server $server)
    {
        try {
            $response = $this->application->post("admin/servers/{$server->pterodactyl_id}/unsuspend");
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }

        if ($response->failed()) {
            throw $this->getException('Failed to unsuspend server', $response->status());
        }

        return $response;
    }

    public function getServerAttributes(?int $panelServerId, bool $deleteOn404 = false)
    {
        if ($panelServerId === null) {
            // Never created on the panel; there is nothing to read.
            return null;
        }

        try {
            $response = $this->application->get("admin/servers/{$panelServerId}");
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }

        if ($response->failed()) {
            if ($response->status() === 404 && $deleteOn404) {
                $server = Server::query()->where('pterodactyl_id', $panelServerId)->first();
                if ($server) {
                    $server->delete();

                    return null;
                }
            }

            throw $this->getException('Failed to get server attributes', $response->status());
        }

        $data = $this->unwrap($response, 'Failed to get server attributes');
        $attributes = $this->mapServer($data['server'] ?? $data);

        // Callers read the address from Pterodactyl's allocations relationship,
        // so the allocation is fetched and presented the same way.
        $allocation = $this->getAllocation($attributes['allocation'] ?? null);

        if ($allocation !== null) {
            $attributes['relationships']['allocations']['data'][] = ['attributes' => $allocation];
        }


        // Same guaranteed shape as the other driver.
        return PanelServerShape::normalise($attributes);
    }

    /**
     * @return array{ip: string, alias: string|null, port: int|null}|null
     */
    private function getAllocation(?int $allocationId): ?array
    {
        if (!$allocationId) {
            return null;
        }

        try {
            $response = $this->application->get("admin/allocations/{$allocationId}");

            if ($response->failed()) {
                return null;
            }

            $body = $response->json();
            $a = $body['data']['allocation'] ?? $body['data'] ?? null;

            if (!is_array($a)) {
                return null;
            }

            return [
                'ip' => $a['ip'] ?? '',
                'alias' => $a['ip_alias'] ?: ($a['node_fqdn'] ?? null),
                'port' => isset($a['port']) ? (int) $a['port'] : null,
            ];
        } catch (Exception $e) {
            Log::warning('FeatherPanelClient: could not read allocation ' . $allocationId . ' - ' . $e->getMessage());

            return null;
        }
    }

    public function updateServer(Server $server, Product $product)
    {
        try {
            return $this->application->patch("admin/servers/{$server->pterodactyl_id}", [
                'memory' => $product->memory,
                'swap' => $product->swap,
                'disk' => $product->disk,
                'io' => $product->io,
                'cpu' => $product->cpu,
                'oom_disabled' => !$product->oom_killer,
                'oom_killer' => (bool) $product->oom_killer,
                'database_limit' => $product->databases,
                'backup_limit' => $product->backups,
                'allocation_limit' => $product->allocations,
            ]);
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    public function updateServerBuild(string $panelServerId, int $allocationId, Product $product)
    {
        try {
            return $this->application->patch("admin/servers/{$panelServerId}", [
                'allocation_id' => $allocationId,
                'memory' => $product->memory,
                'swap' => $product->swap,
                'disk' => $product->disk,
                'io' => $product->io,
                'cpu' => $product->cpu,
                'oom_disabled' => !$product->oom_killer,
                'oom_killer' => (bool) $product->oom_killer,
                'database_limit' => $product->databases,
                'backup_limit' => $product->backups,
                'allocation_limit' => $product->allocations,
            ]);
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    public function updateServerOwner(Server $server, int $userId)
    {
        try {
            return $this->application->patch("admin/servers/{$server->pterodactyl_id}", [
                'owner_id' => $userId,
            ]);
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    public function updateServerDetails(Server $server, array $data)
    {
        try {
            return $this->application->patch("admin/servers/{$server->pterodactyl_id}", $data);
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    public function powerAction(Server $server, $action)
    {
        try {
            // The client route is keyed by the short uuid, which is stored in
            // `identifier` exactly as the Pterodactyl identifier used to be.
            return $this->client->post("user/servers/{$server->identifier}/power/{$action}");
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    /* ---------------------------------------------------------------------
     | Users
     * ------------------------------------------------------------------- */

    public function createUser(array $data)
    {
        return $this->application->put('admin/users', $data);
    }

    public function getUser(int $panelUserId)
    {
        $rows = $this->collect('admin/users', 'users', ['user_id' => $panelUserId], 1);

        if (count($rows) === 0) {
            throw $this->getException('User ' . $panelUserId . ' not found on FeatherPanel', 404);
        }

        return $this->mapUser($rows[0]);
    }

    public function updateUser(int $panelUserId, array $data)
    {
        $uuid = $this->resolveUserUuid($panelUserId);

        if ($uuid === null) {
            throw $this->getException('User ' . $panelUserId . ' not found on FeatherPanel', 404);
        }

        try {
            return $this->application->patch("admin/users/{$uuid}", $data);
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    public function deleteUser(int $panelUserId)
    {
        $uuid = $this->resolveUserUuid($panelUserId);

        if ($uuid === null) {
            // Nothing to remove on the panel side.
            return null;
        }

        try {
            return $this->application->delete("admin/users/{$uuid}");
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    public function getClientUser()
    {
        try {
            return $this->client->get('user/session');
        } catch (Exception $e) {
            throw $this->getException($e->getMessage());
        }
    }

    /**
     * FeatherPanel nodes do not carry memory/disk capacity the way Pterodactyl
     * nodes do, so there is nothing to compare a product against. Capacity is
     * enforced by the node itself at deploy time.
     */
    public function checkNodeResources(Node $node, int $requireMemory, int $requireDisk)
    {
        $capacity = $this->getNodeCapacity($node->id);

        if ($capacity === null) {
            // Capacity unknown: let the panel itself reject the deploy.
            return true;
        }

        return ($capacity['memory'] - $capacity['used_memory']) >= $requireMemory
            && ($capacity['disk'] - $capacity['used_disk']) >= $requireDisk;
    }

    /**
     * The node holds the limits; FeatherPanel has no "allocated_resources"
     * counter, so current usage is summed from the servers placed on it.
     */
    public function getNodeCapacity($nodeId): ?array
    {
        try {
            $response = $this->application->get("admin/nodes/{$nodeId}");
        } catch (Exception $e) {
            throw $this->getException('Failed to get node capacity - ' . $e->getMessage());
        }

        $data = $this->unwrap($response, 'Failed to get node capacity');
        $node = $data['node'] ?? $data;

        if (!isset($node['memory'], $node['disk'])) {
            return null;
        }

        $usedMemory = 0;
        $usedDisk = 0;

        foreach ($this->collect("admin/servers/node/{$nodeId}", 'servers') as $server) {
            $usedMemory += (int) ($server['memory'] ?? 0);
            $usedDisk += (int) ($server['disk'] ?? 0);
        }

        return [
            'memory' => $node['memory'] * (($node['memory_overallocate'] ?? 0) + 100) / 100,
            'disk' => $node['disk'] * (($node['disk_overallocate'] ?? 0) + 100) / 100,
            'used_memory' => $usedMemory,
            'used_disk' => $usedDisk,
        ];
    }

    private function getEnvironmentVariables(Egg $egg, $variables): array
    {
        $environment = [];
        $variables = collect(is_string($variables) ? json_decode($variables, true) : $variables);

        // Same rule as the Pterodactyl driver: only variables the egg marks as
        // the customer's to set may come from a request.
        $editable = [];

        foreach ($egg->environment as $envVariable) {
            if (!empty($envVariable['default_value'])) {
                $environment[$envVariable['env_variable']] = $envVariable['default_value'];
            }

            if (($envVariable['user_editable'] ?? false) && ($envVariable['user_viewable'] ?? false)) {
                $editable[] = $envVariable['env_variable'];
            }
        }

        $submitted = array_intersect_key($variables->toArray(), array_flip($editable));

        return array_merge($environment, $submitted);
    }
}
