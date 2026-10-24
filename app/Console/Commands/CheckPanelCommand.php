<?php

namespace App\Console\Commands;

use App\Contracts\GamePanelClient;
use App\Models\Server;
use App\Settings\PterodactylSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Answers the one question the power buttons cannot: is the game panel
 * reachable, and does the *client* key have any say over customer servers.
 *
 * CtrlPanel keeps two keys. The application key (ptla_) manages servers,
 * users and nodes. The client key (ptlc_) is the one that sends power
 * signals, and on Pterodactyl it may only touch servers its own account owns
 * — unless that account is a root admin. A client key belonging to an
 * ordinary account is the usual reason start/stop/restart answers 404.
 */
class CheckPanelCommand extends Command
{
    protected $signature = 'cp:panel:check
        {--power : also send a real start signal to one server}
        {--server= : which server to probe, by its panel id}';

    protected $description = 'Check the game panel connection and both API keys';

    public function handle(GamePanelClient $client, PterodactylSettings $settings): int
    {
        $this->newLine();
        $this->line('Panel:  <info>' . $client->panelName() . '</info> at ' . $client->panelUrl());
        $this->line('Keys:   application ' . $this->describeKey($settings->admin_token)
            . ', client ' . $this->describeKey($settings->user_token));
        $this->newLine();

        $ok = $this->checkApplicationKey($client);
        $ok = $this->checkClientKey($client) && $ok;
        $ok = $this->checkServer($client) && $ok;

        $this->newLine();

        if (!$ok) {
            $this->warn('Something above needs attention before power controls will work.');

            return self::FAILURE;
        }

        $this->info('Everything checks out.');

        return self::SUCCESS;
    }

    /** Never print a key; its shape is enough to spot the common mix-up. */
    private function describeKey(?string $key): string
    {
        if (empty($key)) {
            return '<error>not set</error>';
        }

        $prefix = str_contains($key, '_') ? strtok($key, '_') . '_' : '(no prefix)';

        return $prefix . '… (' . strlen($key) . ' chars)';
    }

    private function checkApplicationKey(GamePanelClient $client): bool
    {
        try {
            $client->getNodes();
            $this->line(' <info>OK</info>   application key: the panel answered');

            return true;
        } catch (Throwable $e) {
            $this->line(' <error>FAIL</error> application key: ' . $e->getMessage());

            return false;
        }
    }

    private function checkClientKey(GamePanelClient $client): bool
    {
        try {
            $response = $client->getClientUser();
        } catch (Throwable $e) {
            $this->line(' <error>FAIL</error> client key: ' . $e->getMessage());

            return false;
        }

        if ($response->failed()) {
            $this->line(' <error>FAIL</error> client key: the panel answered HTTP ' . $response->status());
            $this->line('        A client key looks like ptlc_…; an application key (ptla_…) will not work here.');

            return false;
        }

        $attributes = $response->json('attributes') ?? $response->json('data') ?? [];
        $name = $attributes['username'] ?? $attributes['email'] ?? 'unknown';
        $admin = (bool) ($attributes['admin'] ?? $attributes['root_admin'] ?? false);

        $this->line(' <info>OK</info>   client key: belongs to ' . $name);

        if ($admin) {
            $this->line(' <info>OK</info>   that account is an administrator, so it can control every server');

            return true;
        }

        $this->line(' <error>FAIL</error> that account is NOT an administrator on the panel');
        $this->line('        Power signals go through the client API, which only reaches servers the key\'s');
        $this->line('        own account owns. Use a client key made on an administrator account.');

        return false;
    }

    private function checkServer(GamePanelClient $client): bool
    {
        // Sending a signal starts somebody's server, so it only happens when
        // asked for. The client-key check above already finds the usual cause.
        if (!$this->option('power')) {
            $this->line(' <comment>SKIP</comment> no power signal sent (pass --power to try one for real)');

            return true;
        }

        $panelId = $this->option('server');

        $server = $panelId
            ? Server::where('pterodactyl_id', $panelId)->first()
            : Server::whereNotNull('identifier')->whereNull('suspended')->whereNull('canceled')->first();

        if (!$server) {
            $this->line(' <comment>SKIP</comment> no server to probe');

            return true;
        }

        try {
            $response = $client->powerAction($server, 'start');
        } catch (Throwable $e) {
            $this->line(' <error>FAIL</error> power signal to "' . $server->name . '": ' . $e->getMessage());

            return false;
        }

        if ($response->successful()) {
            $this->line(' <info>OK</info>   power signal accepted for "' . $server->name . '" (it was sent a start)');

            return true;
        }

        $this->line(' <error>FAIL</error> power signal to "' . $server->name . '": HTTP ' . $response->status());
        $this->line('        ' . trim($response->body()));

        return false;
    }
}
