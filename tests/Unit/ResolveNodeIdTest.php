<?php

namespace Tests\Unit;

use App\Models\Server;
use Tests\TestCase;

/**
 * Which node a server sits on arrives in a different shape from each panel,
 * and on some routes not at all. Reading one shape blindly raised
 * "Undefined array key node" and took the upgrade page down with it.
 */
class ResolveNodeIdTest extends TestCase
{
    public function test_the_nested_pterodactyl_shape_is_understood(): void
    {
        $this->assertSame(7, Server::resolveNodeId([
            'relationships' => ['node' => ['attributes' => ['id' => 7]]],
        ]));
    }

    public function test_the_flat_shape_is_understood(): void
    {
        $this->assertSame(4, Server::resolveNodeId(['node' => 4]));
        $this->assertSame(4, Server::resolveNodeId(['attributes' => ['node' => 4]]));
    }

    public function test_the_nested_shape_wins_when_both_are_present(): void
    {
        $this->assertSame(7, Server::resolveNodeId([
            'node' => 4,
            'relationships' => ['node' => ['attributes' => ['id' => 7]]],
        ]));
    }

    public function test_our_own_record_is_the_last_resort(): void
    {
        $server = new Server();
        $server->node_id = 9;

        $this->assertSame(9, Server::resolveNodeId([], $server));
    }

    public function test_an_unknown_node_reads_as_null_rather_than_throwing(): void
    {
        $this->assertNull(Server::resolveNodeId([]));
        $this->assertNull(Server::resolveNodeId(['relationships' => []], new Server()));
    }

    /**
     * The allocation travels in the same payloads and used to be read just as
     * blindly, so it is pinned down here alongside.
     */
    public function test_the_allocation_is_found_in_either_shape(): void
    {
        $resolve = fn (array $info) => \App\Http\Controllers\ServerController::resolveAllocation($info);

        $this->assertSame(12, $resolve(['allocation' => 12]));
        $this->assertSame(12, $resolve(['attributes' => ['allocation' => 12]]));
        $this->assertSame(34, $resolve([
            'relationships' => ['allocations' => ['data' => [['attributes' => ['id' => 34]]]]],
        ]));
        $this->assertNull($resolve([]));
        $this->assertNull($resolve(['relationships' => ['allocations' => ['data' => []]]]));
    }
}
