<?php

namespace Tests\Unit;

use App\Models\Server;
use Tests\TestCase;

/**
 * The servers list decorates each row with names read from the game panel.
 * Those are not columns, and letting them reach $attributes made the next
 * save() write them — "Unknown column 'location'" — and lose whatever that
 * save was actually for.
 */
class ServerPanelInfoTest extends TestCase
{
    public function test_panel_details_are_readable_but_never_become_attributes(): void
    {
        $server = new Server(['name' => 'Test']);

        $server->location = 'Warszawa';
        $server->egg = 'Paper';
        $server->nest = 'Minecraft';
        $server->node = 'node1';
        $server->address = 'node1.example.com:25565';
        $server->state = 'running';

        $this->assertSame('Warszawa', $server->location);
        $this->assertSame('running', $server->state);

        foreach (Server::PANEL_INFO_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $server->getAttributes());
            $this->assertArrayNotHasKey($field, $server->getDirty());
        }
    }

    public function test_a_real_column_still_behaves_normally(): void
    {
        $server = new Server(['name' => 'Test']);
        $server->name = 'Renamed';

        $this->assertSame('Renamed', $server->name);
        $this->assertArrayHasKey('name', $server->getAttributes());
    }

    public function test_an_unset_detail_reads_as_null(): void
    {
        $this->assertNull((new Server())->address);
    }
}
