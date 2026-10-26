<?php

namespace Tests\Unit;

use App\Classes\PanelServerShape;
use Tests\TestCase;

/**
 * Controllers, services and views all reach into server payloads, and the two
 * panels disagree about which keys they send. Every missing one was a 500
 * waiting for whoever read it first. The drivers push their answer through
 * here so the keys are always there, null when unknown.
 */
class PanelServerShapeTest extends TestCase
{
    public function test_the_relations_are_always_present(): void
    {
        // A FeatherPanel-shaped answer: flat ids, no relationships at all.
        $shape = PanelServerShape::normalise(['id' => 5, 'node' => 2, 'egg' => 7]);

        $this->assertNull($shape['relationships']['location']['attributes']['short']);
        $this->assertNull($shape['relationships']['node']['attributes']['name']);
        $this->assertSame([], $shape['relationships']['allocations']['data']);
        $this->assertNull($shape['limits']['memory']);
        $this->assertNull($shape['feature_limits']['backups']);
    }

    public function test_what_the_panel_sent_is_never_overwritten(): void
    {
        $shape = PanelServerShape::normalise([
            'id' => 5,
            'name' => 'Mine',
            'limits' => ['memory' => 2048],
            'relationships' => [
                'location' => ['attributes' => ['short' => 'WAW']],
            ],
        ]);

        $this->assertSame('Mine', $shape['name']);
        $this->assertSame(2048, $shape['limits']['memory']);
        $this->assertSame('WAW', $shape['relationships']['location']['attributes']['short']);
        // Filled in alongside, not instead of.
        $this->assertNull($shape['limits']['disk']);
        $this->assertNull($shape['relationships']['location']['attributes']['long']);
    }

    public function test_an_empty_payload_still_has_every_key(): void
    {
        $shape = PanelServerShape::normalise([]);

        $this->assertNull($shape['identifier']);
        $this->assertNull($shape['allocation']);
        $this->assertNull($shape['relationships']['nest']['attributes']['name']);
    }

    public function test_a_null_the_panel_sent_is_kept_as_null(): void
    {
        $shape = PanelServerShape::normalise(['name' => null]);

        $this->assertArrayHasKey('name', $shape);
        $this->assertNull($shape['name']);
    }
}
