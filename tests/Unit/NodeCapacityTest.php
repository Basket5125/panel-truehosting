<?php

namespace Tests\Unit;

use App\Classes\PterodactylClient;
use App\Models\Pterodactyl\Node;
use App\Settings\PterodactylSettings;
use App\Settings\ServerSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A node that is full has to read as full.
 *
 * The old check read `allocated_resources` straight off the node payload. A
 * panel that does not send that key made the subtraction treat usage as zero,
 * so a node with no room left looked empty, accepted the order, and the panel
 * refused it afterwards — leaving a server record with nothing behind it.
 */
class NodeCapacityTest extends TestCase
{
    private function client(array $nodePayload): PterodactylClient
    {
        Http::preventStrayRequests();
        Http::fake(['*/application/nodes/*' => Http::response(['attributes' => $nodePayload], 200)]);

        ServerSettings::fake([
            'creation_enabled' => true, 'enable_upgrade' => true,
            'location_description_enabled' => false, 'power_controls_enabled' => false,
            'prefer_newest_java' => true, 'allocation_limit' => 0,
        ], false);

        return new PterodactylClient(PterodactylSettings::fake([
            'panel_url' => 'https://panel.example.com',
            'admin_token' => 'ptla_test', 'user_token' => 'ptlc_test', 'per_page_limit' => 50,
        ], false));
    }

    private function node(): Node
    {
        return new Node(['id' => 1, 'name' => 'node1']);
    }

    public function test_a_node_with_room_accepts_the_server(): void
    {
        $client = $this->client([
            'memory' => 16384, 'disk' => 100000,
            'memory_overallocate' => 0, 'disk_overallocate' => 0,
            'allocated_resources' => ['memory' => 2048, 'disk' => 10000],
        ]);

        $this->assertTrue($client->checkNodeResources($this->node(), 4096, 20000));
    }

    public function test_a_full_node_is_refused(): void
    {
        $client = $this->client([
            'memory' => 16384, 'disk' => 100000,
            'memory_overallocate' => 0, 'disk_overallocate' => 0,
            'allocated_resources' => ['memory' => 16000, 'disk' => 99000],
        ]);

        $this->assertFalse($client->checkNodeResources($this->node(), 4096, 20000));
    }

    public function test_overallocation_is_taken_into_account(): void
    {
        $client = $this->client([
            'memory' => 10000, 'disk' => 10000,
            'memory_overallocate' => 100, 'disk_overallocate' => 100,
            'allocated_resources' => ['memory' => 10000, 'disk' => 10000],
        ]);

        // Twice the memory is allowed, so half of it is still free.
        $this->assertTrue($client->checkNodeResources($this->node(), 5000, 5000));
        $this->assertFalse($client->checkNodeResources($this->node(), 50000, 5000));
    }

    public function test_capacity_is_unknown_rather_than_zero_when_the_panel_omits_it(): void
    {
        $client = $this->client([
            'memory' => 16384, 'disk' => 100000,
            'memory_overallocate' => 0, 'disk_overallocate' => 0,
            // No allocated_resources at all, as on the panel this fork runs against.
        ]);

        $this->assertNull($client->getNodeCapacity(1));
    }
}
