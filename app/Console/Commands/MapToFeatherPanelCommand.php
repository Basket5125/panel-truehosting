<?php

namespace App\Console\Commands;

use App\Classes\FeatherPanelClient;
use App\Classes\PterodactylClient;
use App\Models\Server;
use App\Models\User;
use App\Settings\FeatherPanelSettings;
use App\Settings\PterodactylSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-points `pterodactyl_id` columns from Pterodactyl ids to FeatherPanel ids.
 *
 * Both panels are queried directly, so this must run while the Pterodactyl API
 * is still reachable. Nothing is written unless --write is passed.
 */
class MapToFeatherPanelCommand extends Command
{
    protected $signature = 'cp:featherpanel:map
                            {--write : Persist the changes (otherwise this is a dry run)}
                            {--skip-users : Leave users alone}
                            {--skip-servers : Leave servers alone}';

    protected $description = 'Map existing Pterodactyl ids to their FeatherPanel equivalents';

    private PterodactylClient $ptero;

    private FeatherPanelClient $feather;

    public function handle(PterodactylSettings $pteroSettings, FeatherPanelSettings $featherSettings): int
    {
        if (empty($featherSettings->panel_url) || empty($featherSettings->admin_token)) {
            $this->error('Set the FeatherPanel URL and admin API key in the panel settings first.');

            return self::FAILURE;
        }

        $this->ptero = new PterodactylClient($pteroSettings);
        $this->feather = new FeatherPanelClient($featherSettings);

        $write = (bool) $this->option('write');

        if (!$write) {
            $this->warn('Dry run. Nothing is written. Re-run with --write once the table below looks right.');
        }

        $ok = true;

        if (!$this->option('skip-users')) {
            $ok = $this->mapUsers($write) && $ok;
        }

        if (!$this->option('skip-servers')) {
            $ok = $this->mapServers($write) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Users are matched on their email address, which both panels hold.
     */
    private function mapUsers(bool $write): bool
    {
        $this->newLine();
        $this->info('Users');

        $rows = [];
        $unmatched = 0;

        foreach (User::whereNotNull('pterodactyl_id')->cursor() as $user) {
            $email = $user->email;
            $match = $this->findFeatherUserByEmail($email);

            if ($match === null) {
                $rows[] = [$user->id, $user->name, $user->pterodactyl_id, '—', 'not found'];
                $unmatched++;

                continue;
            }

            $newId = (int) $match['id'];

            if ($newId === (int) $user->pterodactyl_id) {
                $rows[] = [$user->id, $user->name, $user->pterodactyl_id, $newId, 'already correct'];

                continue;
            }

            $rows[] = [$user->id, $user->name, $user->pterodactyl_id, $newId, $write ? 'updated' : 'would update'];

            if ($write) {
                DB::table('users')->where('id', $user->id)->update(['pterodactyl_id' => $newId]);
            }
        }

        $this->table(['user', 'name', 'old id', 'new id', 'status'], $rows);

        if ($unmatched > 0) {
            $this->warn($unmatched . ' user(s) had no match on FeatherPanel. Create them there first, or they will fail on their next server action.');
        }

        return $unmatched === 0;
    }

    /**
     * Servers are matched on external_id, which CtrlPanel writes when it
     * creates them. Anything without a match is reported rather than guessed.
     */
    private function mapServers(bool $write): bool
    {
        $this->newLine();
        $this->info('Servers');

        $rows = [];
        $unmatched = 0;

        foreach (Server::whereNotNull('pterodactyl_id')->cursor() as $server) {
            $match = $this->findFeatherServerByExternalId($server->id);

            if ($match === null) {
                $rows[] = [$server->id, $server->name, $server->pterodactyl_id, '—', 'not found'];
                $unmatched++;

                continue;
            }

            $newId = (int) ($match['id'] ?? 0);
            $identifier = $match['uuidShort'] ?? $server->identifier;

            if ($newId === (int) $server->pterodactyl_id && $identifier === $server->identifier) {
                $rows[] = [$server->id, $server->name, $server->pterodactyl_id, $newId, 'already correct'];

                continue;
            }

            $rows[] = [$server->id, $server->name, $server->pterodactyl_id, $newId, $write ? 'updated' : 'would update'];

            if ($write) {
                DB::table('servers')->where('id', $server->id)->update([
                    'pterodactyl_id' => $newId,
                    'identifier' => $identifier,
                ]);
            }
        }

        $this->table(['server', 'name', 'old id', 'new id', 'status'], $rows);

        if ($unmatched > 0) {
            $this->warn($unmatched . ' server(s) had no match on FeatherPanel by external_id. Import them there keeping external_id, or map them by hand.');
        }

        return $unmatched === 0;
    }

    private function findFeatherUserByEmail(string $email): ?array
    {
        try {
            $response = $this->feather->application->get('admin/users', ['search' => $email, 'limit' => 5]);

            if ($response->failed()) {
                return null;
            }

            $body = $response->json();
            $users = $body['data']['users'] ?? $body['users'] ?? [];

            foreach ($users as $candidate) {
                if (strcasecmp($candidate['email'] ?? '', $email) === 0) {
                    return $candidate;
                }
            }
        } catch (Throwable $e) {
            $this->error('FeatherPanel lookup failed for ' . $email . ': ' . $e->getMessage());
        }

        return null;
    }

    private function findFeatherServerByExternalId(string $externalId): ?array
    {
        try {
            $response = $this->feather->application->get("admin/servers/external/{$externalId}");

            if ($response->failed()) {
                return null;
            }

            $body = $response->json();

            return $body['data']['server'] ?? $body['data'] ?? $body['server'] ?? null;
        } catch (Throwable $e) {
            $this->error('FeatherPanel lookup failed for server ' . $externalId . ': ' . $e->getMessage());
        }

        return null;
    }
}
