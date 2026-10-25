<?php

namespace Tests\Unit;

use App\Classes\PterodactylClient;
use App\Models\Product;
use App\Settings\PterodactylSettings;
use App\Settings\ServerSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two panels name this setting in opposite directions: CtrlPanel stores
 * `oom_killer` (on means the killer runs), Pterodactyl wants `oom_disabled`
 * (on means it does not). One call site had forgotten the negation, so
 * upgrading a server switched the OOM killer on for products that had it off.
 */
class OomKillerFlagTest extends TestCase
{
    private function sentPayload(bool $oomKiller): array
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);

        $product = new Product([
            'name' => 'Test', 'memory' => 1024, 'swap' => 0, 'disk' => 5120,
            'io' => 500, 'cpu' => 100, 'databases' => 1, 'backups' => 1,
            'allocations' => 1, 'oom_killer' => $oomKiller,
        ]);

        // fake() keeps both settings objects out of the database, so this
        // test needs no migrated schema.
        $settings = PterodactylSettings::fake([
            'panel_url' => 'https://panel.example.com',
            'admin_token' => 'ptla_test',
            'user_token' => 'ptlc_test',
            'per_page_limit' => 50,
        ], false);

        ServerSettings::fake([
            'creation_enabled' => true,
            'enable_upgrade' => true,
            'location_description_enabled' => false,
            'power_controls_enabled' => false,
            'prefer_newest_java' => true,
            'allocation_limit' => 0,
        ], false);

        (new PterodactylClient($settings))->updateServerBuild('123', 7, $product);

        $sent = [];
        Http::recorded(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        return $sent;
    }

    public function test_an_unchecked_oom_killer_disables_it_on_the_panel(): void
    {
        $payload = $this->sentPayload(false);

        // Pterodactyl's wording: the killer is disabled.
        $this->assertTrue($payload['oom_disabled']);
        // FeatherPanel's wording, sent alongside: the killer is off.
        $this->assertFalse($payload['oom_killer']);
    }

    public function test_a_checked_oom_killer_leaves_it_running(): void
    {
        $payload = $this->sentPayload(true);

        $this->assertFalse($payload['oom_disabled']);
        $this->assertTrue($payload['oom_killer']);
    }
}
