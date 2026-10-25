<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Settings\TrialSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes the configured action on trials whose time is up.
 *
 * Suspending keeps the files, so buying later carries on where the trial
 * stopped; deleting frees the node straight away. Either way the trial stays
 * counted against the account, so nobody gets a second one for free.
 */
class ExpireTrialsCommand extends Command
{
    protected $signature = 'cp:trials:expire';

    protected $description = 'Suspend or remove trial servers whose time is up';

    public function handle(TrialSettings $settings): int
    {
        $delete = $settings->end_action === 'delete';
        $handled = 0;
        $failed = 0;

        Server::whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->whereNull('canceled')
            ->chunkById(50, function ($servers) use ($delete, &$handled, &$failed) {
                foreach ($servers as $server) {
                    try {
                        if ($delete) {
                            $server->delete();
                        } else {
                            if ($server->suspended) {
                                continue;
                            }

                            $server->suspend();
                        }

                        $handled++;
                    } catch (Throwable $e) {
                        $failed++;
                        logger()->warning('Could not finish a trial server', [
                            'server_id' => $server->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($handled > 0 || $failed > 0) {
            $this->info(($delete ? 'Removed ' : 'Suspended ') . $handled . ' expired trial(s).');
        }

        if ($failed > 0) {
            $this->warn($failed . ' could not be finished; see the log.');
        }

        return self::SUCCESS;
    }
}
