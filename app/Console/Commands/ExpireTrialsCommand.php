<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Settings\TrialSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes the configured action on trials whose time is up.
 *
 * With "delete" the server goes straight away. With "suspend" it is paused,
 * which keeps the files so buying it later carries on where the trial left
 * off — and, if the operator set a window, it is removed once that window has
 * passed as well, so an abandoned trial does not sit on a node for ever.
 *
 * Both passes run every time, so a trial that was suspended on an earlier run
 * is picked up for removal on a later one.
 */
class ExpireTrialsCommand extends Command
{
    protected $signature = 'cp:trials:expire';

    protected $description = 'Suspend or remove trial servers whose time is up';

    public function handle(TrialSettings $settings): int
    {
        if ($settings->end_action === 'delete') {
            $this->report('Removed', $this->removeExpired());

            return self::SUCCESS;
        }

        $this->report('Suspended', $this->suspendExpired());

        $keepFor = max(0, (int) $settings->delete_after_hours);

        if ($keepFor > 0) {
            $this->report('Removed after the grace window', $this->removeLongSuspended($keepFor));
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} handled, failed */
    private function removeExpired(): array
    {
        return $this->walk(
            $this->expired(),
            fn (Server $server) => $server->delete()
        );
    }

    /** @return array{0: int, 1: int} */
    private function suspendExpired(): array
    {
        return $this->walk(
            $this->expired()->whereNull('suspended'),
            fn (Server $server) => $server->suspend()
        );
    }

    /**
     * Trials that have been sitting suspended longer than the operator wants.
     *
     * Only expired trials are in scope, so a trial an administrator suspended
     * by hand before its time is not swept up by this.
     *
     * @return array{0: int, 1: int}
     */
    private function removeLongSuspended(int $keepForHours): array
    {
        return $this->walk(
            $this->expired()
                ->whereNotNull('suspended')
                ->where('suspended', '<=', now()->subHours($keepForHours)),
            fn (Server $server) => $server->delete()
        );
    }

    private function expired()
    {
        return Server::whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->whereNull('canceled');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return array{0: int, 1: int}
     */
    private function walk($query, callable $action): array
    {
        $handled = 0;
        $failed = 0;

        // chunkById, because both actions change the rows being walked.
        $query->chunkById(50, function ($servers) use ($action, &$handled, &$failed) {
            foreach ($servers as $server) {
                try {
                    $action($server);
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

        return [$handled, $failed];
    }

    /** @param array{0: int, 1: int} $result */
    private function report(string $what, array $result): void
    {
        [$handled, $failed] = $result;

        if ($handled > 0) {
            $this->info($what . ' ' . $handled . ' trial server(s).');
        }

        if ($failed > 0) {
            $this->warn($failed . ' could not be finished; see the log.');
        }
    }
}
