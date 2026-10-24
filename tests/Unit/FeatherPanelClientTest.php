<?php

namespace Tests\Unit;

use App\Classes\FeatherPanelClient;
use App\Models\Pterodactyl\Nest;
use App\Models\Pterodactyl\Node;
use App\Settings\FeatherPanelSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The rest of the application reads Pterodactyl-shaped payloads, so the value
 * of this driver is its translation. These cases pin that translation down
 * without touching a real panel.
 */
class FeatherPanelClientTest extends TestCase
{
    private function envelope(array $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A request this test did not fake would otherwise go out to the
        // network; fail instead.
        Http::preventStrayRequests();
    }

    private function client(): FeatherPanelClient
    {
        // fake() keeps the settings out of the database, so the test needs no
        // migrated schema.
        $settings = FeatherPanelSettings::fake([
            'driver' => 'featherpanel',
            'panel_url' => 'https://panel.example.com',
            'admin_token' => 'test-key',
            'user_token' => '',
            'per_page_limit' => 50,
        ], false);

        return new FeatherPanelClient($settings);
    }

    public function test_locations_are_translated_to_the_short_long_shape(): void
    {
        Http::fake(['*/api/admin/locations*' => Http::response($this->envelope([
            'locations' => [['id' => 3, 'name' => 'Warszawa', 'description' => 'PL-WAW']],
            'pagination' => ['has_next' => false],
        ]))]);

        $locations = $this->client()->getLocations();

        $this->assertSame('Warszawa', $locations[0]['attributes']['short']);
        $this->assertSame('PL-WAW', $locations[0]['attributes']['long']);
    }

    public function test_realms_are_returned_as_nests(): void
    {
        Http::fake(['*/api/admin/realms*' => Http::response($this->envelope([
            'realms' => [['id' => 7, 'name' => 'Minecraft', 'description' => 'MC']],
            'pagination' => ['has_next' => false],
        ]))]);

        $nests = $this->client()->getNests();

        $this->assertSame(7, $nests[0]['attributes']['id']);
        $this->assertSame('Minecraft', $nests[0]['attributes']['name']);
    }

    public function test_spells_are_returned_as_eggs_with_their_variables(): void
    {
        Http::fake([
            '*/api/admin/spells/realm/*' => Http::response($this->envelope([
                'spells' => [[
                    'id' => 21, 'realm_id' => 7, 'name' => 'Paper', 'description' => 'Paper MC',
                    'default_docker_image' => 'ghcr.io/pterodactyl/yolks:java_17',
                    'startup' => 'java -jar server.jar',
                ]],
                'pagination' => ['has_next' => false],
            ])),
            '*/api/admin/spells/21/variables*' => Http::response($this->envelope([
                'variables' => [[
                    'name' => 'Server jar', 'description' => 'jar file', 'default_value' => 'server.jar',
                    'env_variable' => 'SERVER_JARFILE', 'user_viewable' => true,
                    'user_editable' => true, 'rules' => 'required|string',
                ]],
            ])),
        ]);

        $nest = new Nest();
        $nest->id = 7;

        $eggs = $this->client()->getEggs($nest);
        $attributes = $eggs[0]['attributes'];

        $this->assertSame(7, $attributes['nest']);
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_17', $attributes['docker_image']);
        $this->assertSame(
            'SERVER_JARFILE',
            $attributes['relationships']['variables']['data'][0]['attributes']['env_variable']
        );
    }

    public function test_spell_without_a_default_image_falls_back_to_docker_images(): void
    {
        Http::fake([
            '*/api/admin/spells/realm/*' => Http::response($this->envelope([
                'spells' => [[
                    'id' => 31, 'realm_id' => 7, 'name' => 'Bungeecord',
                    'default_docker_image' => '',
                    'docker_images' => '{"Java 21":"ghcr.io/pterodactyl/yolks:java_21","Java 17":"ghcr.io/pterodactyl/yolks:java_17"}',
                    'startup' => 'java -jar bungee.jar',
                ]],
                'pagination' => ['has_next' => false],
            ])),
            '*/api/admin/spells/31/variables*' => Http::response($this->envelope(['variables' => []])),
        ]);

        $nest = new Nest();
        $nest->id = 7;

        $eggs = $this->client()->getEggs($nest);

        $this->assertSame('ghcr.io/pterodactyl/yolks:java_21', $eggs[0]['attributes']['docker_image']);
    }

    public function test_free_allocation_id_comes_from_the_available_endpoint(): void
    {
        Http::fake(['*/api/admin/allocations/available*' => Http::response($this->envelope([
            'allocations' => [['id' => 55, 'ip' => '10.0.0.5', 'port' => 25565, 'node_id' => 11, 'server_id' => null]],
            'pagination' => ['has_next' => false],
        ]))]);

        $node = new Node();
        $node->id = 11;

        $this->assertSame(55, $this->client()->getFreeAllocationId($node));
    }

    public function test_server_attributes_keep_the_field_names_callers_expect(): void
    {
        Http::fake([
            '*/api/admin/allocations/55*' => Http::response($this->envelope([
                'allocation' => [
                    'id' => 55, 'ip' => '10.0.0.5', 'ip_alias' => 'node1.example.com',
                    'port' => 25565, 'node_id' => 11, 'node_fqdn' => 'node1.example.com',
                ],
            ])),
            '*/api/admin/servers/9*' => Http::response($this->envelope([
            'server' => [
                'id' => 9, 'external_id' => 'ctrl-abc', 'uuid' => 'srv-uuid', 'uuidShort' => 'a1b2c3d4',
                'name' => 'Serwer', 'suspended' => false, 'owner_id' => 42, 'node_id' => 11,
                'realms_id' => 7, 'spell_id' => 21, 'allocation_id' => 55,
                'memory' => 4096, 'swap' => 0, 'disk' => 20480, 'io' => 500, 'cpu' => 200,
                'oom_killer' => true, 'database_limit' => 2, 'backup_limit' => 3, 'allocation_limit' => 1,
            ],
            ])),
        ]);

        $server = $this->client()->getServerAttributes(9);

        $this->assertSame('a1b2c3d4', $server['identifier']);
        $this->assertSame(42, $server['user']);
        $this->assertSame(21, $server['egg']);
        $this->assertSame(4096, $server['limits']['memory']);
        // oom_killer on the product means OOM is *not* disabled on the panel.
        $this->assertFalse($server['limits']['oom_disabled']);
        $this->assertSame(3, $server['feature_limits']['backups']);

        // The address is presented the way the server list reads it.
        $allocation = $server['relationships']['allocations']['data'][0]['attributes'];
        $this->assertSame('node1.example.com', $allocation['alias']);
        $this->assertSame(25565, $allocation['port']);
    }

    public function test_users_are_looked_up_by_numeric_id_and_carry_their_uuid(): void
    {
        Http::fake(['*/api/admin/users*' => Http::response($this->envelope([
            'users' => [['id' => 42, 'uuid' => 'u-uuid-42', 'username' => 'kowalski', 'email' => 'jan@example.com']],
            'pagination' => ['has_next' => false],
        ]))]);

        $user = $this->client()->getUser(42);

        $this->assertSame(42, $user['id']);
        $this->assertSame('u-uuid-42', $user['uuid']);
    }
}
