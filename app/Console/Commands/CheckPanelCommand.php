<?php

namespace App\Console\Commands;

use App\Contracts\GamePanelClient;
use App\Models\Server;
use App\Services\GamePanelFallback;
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

    /**
     * Never print a key. The prefix is shown only so two keys can be told
     * apart at a glance — it says nothing about what a key is good for.
     */
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
        $accepted = null;

        $nodes = (new GamePanelFallback($client))->value(
            function (GamePanelClient $candidate) use (&$accepted) {
                $result = $candidate->getNodes();
                $accepted = $candidate;

                return $result;
            },
            'check:application-key',
            fn ($result) => is_array($result)
        );

        if ($nodes === null) {
            $this->line(' <error>FAIL</error> application key: no panel accepted it');

            return false;
        }

        $via = $accepted?->panelName() ?? $client->panelName();
        $this->line(' <info>OK</info>   application key: accepted by ' . $via
            . ' (' . count($nodes) . ' node(s) listed)');

        return true;
    }

    /**
     * Try the client key the way the application does: against the configured
     * driver and, failing that, against the other one.
     *
     * The key's prefix is not evidence of anything. FeatherPanel hands out
     * keys that begin with ptla_ and uses them for the client endpoints too,
     * so a name-based verdict would be wrong on exactly the install this fork
     * runs against. Only the panel's answer counts.
     */
    private function checkClientKey(GamePanelClient $client): bool
    {
        $fallback = new GamePanelFallback($client);
        $accepted = null;

        $response = $fallback->value(
            function (GamePanelClient $candidate) use (&$accepted) {
                $result = $candidate->getClientUser();

                if ($result !== null && $result->successful()) {
                    $accepted = $candidate;
                }

                return $result;
            },
            'check:client-key',
            fn ($result) => $result !== null && $result->successful()
        );

        if ($response === null || $response->failed()) {
            $status = $response?->status() ?? 0;

            $this->line(' <error>FAIL</error> client key: no panel accepted it'
                . ($status ? ' (last answer: HTTP ' . $status . ')' : ''));
            $this->line('        Power signals go through the client endpoints. Create a fresh API key');
            $this->line('        on an administrator account in the panel and put it in the client key');
            $this->line('        field; the prefix does not matter, only that the panel issued it.');

            return false;
        }

        $via = $accepted?->panelName() ?? $client->panelName();

        if ($via !== $client->panelName()) {
            $this->line(' <comment>NOTE</comment> the configured panel refused the client key, but '
                . $via . ' accepted it');
            $this->line('        Power controls will work through the fallback. Set the driver to '
                . $via . ' to stop paying for the extra request each time.');
        }

        $attributes = $response->json('attributes')
            ?? $response->json('data.user')
            ?? $response->json('data')
            ?? [];

        $name = $attributes['username'] ?? $attributes['email'] ?? 'unknown';
        $admin = (bool) ($attributes['admin'] ?? $attributes['root_admin'] ?? false);

        $this->line(' <info>OK</info>   client key: accepted by ' . $via . ', belongs to ' . $name);

        if ($admin) {
            $this->line(' <info>OK</info>   that account is an administrator, so it can control every server');

            return true;
        }

        $this->line(' <error>FAIL</error> that account is NOT an administrator on the panel');
        $this->line('        The client endpoints only reach servers the key\'s own account owns, so');
        $this->line('        customer servers stay out of reach. Use a key made on an admin account.');

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

        // Through the fallback, because that is how the application sends
        // these: a refusal from the configured panel is retried on the other.
        $attempt = (new GamePanelFallback($client))->attempt(
            fn (GamePanelClient $candidate) => $candidate->powerAction($server, 'start'),
            'check:power'
        );

        $response = $attempt['response'];

        if ($response === null) {
            $this->line(' <error>FAIL</error> power signal to "' . $server->name . '": no panel could be reached');

            return false;
        }

        if ($response->successful()) {
            $this->line(' <info>OK</info>   power signal accepted by ' . $attempt['client']->panelName()
                . ' for "' . $server->name . '" (it was sent a start)');

            return true;
        }

        $this->line(' <error>FAIL</error> power signal to "' . $server->name . '": HTTP ' . $response->status());
        $this->line('        ' . trim($response->body()));

        return false;
    }
}
