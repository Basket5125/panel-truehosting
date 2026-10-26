<?php

namespace Tests\Unit;

use App\Classes\PterodactylClient;
use App\Contracts\GamePanelClient;
use App\Models\User;
use App\Services\PanelUserSync;
use App\Settings\PterodactylSettings;
use App\Settings\ServerSettings;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The sync exists to repair accounts that are here but not on the game panel.
 * Two things decide whether it does that safely: it must see the panel's whole
 * list, and the username it invents must be one the panel will take.
 */
class PanelUserSyncTest extends TestCase
{
    private function sync(): PanelUserSync
    {
        return new PanelUserSync(Mockery::mock(GamePanelClient::class));
    }

    private function user(string $name, int $id): User
    {
        $user = new User();
        $user->forceFill(['id' => $id, 'name' => $name]);

        return $user;
    }

    public function test_a_display_name_is_reduced_to_what_a_panel_accepts(): void
    {
        $this->assertSame('zazolcgesla', $this->sync()->panelUsername($this->user('Zażółć gęślą', 7)));
        $this->assertSame('jan.kowalski-1', $this->sync()->panelUsername($this->user('Jan.Kowalski-1', 7)));
    }

    public function test_a_name_with_nothing_usable_in_it_falls_back_to_the_id(): void
    {
        // Two characters is below what panels accept, and a name in a script
        // that does not transliterate reduces to nothing at all.
        $this->assertSame('user7', $this->sync()->panelUsername($this->user('ab', 7)));
        $this->assertSame('user7', $this->sync()->panelUsername($this->user('日本語', 7)));

        // Cyrillic does transliterate, and the result is a fine username.
        $this->assertSame('privet', $this->sync()->panelUsername($this->user('Привет', 7)));
    }

    public function test_a_name_the_panel_already_has_is_given_the_local_id(): void
    {
        $taken = ['kowalski' => true];

        $this->assertSame('kowalski-7', $this->sync()->panelUsername($this->user('Kowalski', 7), $taken));

        // And if that is somehow taken too, it keeps going rather than
        // handing the panel a duplicate it will refuse.
        $taken['kowalski-7'] = true;
        $this->assertSame('kowalski-7-2', $this->sync()->panelUsername($this->user('Kowalski', 7), $taken));
    }

    public function test_every_page_of_panel_accounts_is_read(): void
    {
        // A sync that stops at page one believes every account after it is
        // missing, and would create a second copy of each.
        Http::fakeSequence('*/application/users*')
            ->push([
                'data' => [['attributes' => ['id' => 1, 'email' => 'a@example.com']]],
                'meta' => ['pagination' => ['total_pages' => 2]],
            ], 200)
            ->push([
                'data' => [['attributes' => ['id' => 2, 'email' => 'b@example.com']]],
                'meta' => ['pagination' => ['total_pages' => 2]],
            ], 200);

        ServerSettings::fake([
            'creation_enabled' => true, 'enable_upgrade' => true,
            'location_description_enabled' => false, 'power_controls_enabled' => false,
            'prefer_newest_java' => true, 'allocation_limit' => 0,
        ], false);

        $client = new PterodactylClient(PterodactylSettings::fake([
            'panel_url' => 'https://panel.example.com',
            'admin_token' => 'ptla_test', 'user_token' => 'ptlc_test', 'per_page_limit' => 1,
        ], false));

        $users = $client->getUsers();

        $this->assertCount(2, $users);
        $this->assertSame(2, $users[1]['attributes']['id']);
    }
}
