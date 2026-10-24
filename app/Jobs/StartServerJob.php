<?php

namespace App\Jobs;

use App\Contracts\GamePanelClient;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Starts a freshly created server, so the customer does not land on a box
 * that is sitting there switched off.
 *
 * The first attempt is made a few seconds after creation. The panel is still
 * running the egg's install script at that point more often than not, and it
 * refuses a power signal while it does, so a refusal is retried rather than
 * treated as a failure.
 */
class StartServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How long after creation the first attempt is made. */
    public const DELAY_SECONDS = 5;

    /** Attempts, and how long to wait before each retry. */
    public int $tries = 6;

    private const RETRY_AFTER = [10, 20, 30, 60, 120];

    public function __construct(public string $serverId)
    {
        $this->queue = 'default';
    }

    public function handle(GamePanelClient $client): void
    {
        $server = Server::find($this->serverId);

        // Deleted, cancelled, suspended or never finished provisioning: there
        // is nothing to start, and retrying would not change that.
        if (!$server
            || !$server->identifier
            || $server->status !== Server::STATUS_ACTIVE
            || $server->suspended
            || $server->canceled) {
            return;
        }

        try {
            $response = $client->powerAction($server, 'start');

            if ($response->successful()) {
                return;
            }

            $this->retryOrGiveUp($server, 'HTTP ' . $response->status());
        } catch (Throwable $e) {
            $this->retryOrGiveUp($server, $e->getMessage());
        }
    }

    /**
     * An install in progress is the ordinary case here, not an error, so the
     * reason is only written to the log once the retries are spent.
     */
    private function retryOrGiveUp(Server $server, string $reason): void
    {
        $attempt = max(1, $this->attempts());

        // Without a worker behind it (QUEUE_CONNECTION=sync) the job runs
        // inline, the delay is ignored and a release goes nowhere, so there is
        // no point pretending a retry is coming. Say so instead.
        if ($this->job !== null && $attempt < $this->tries) {
            $wait = self::RETRY_AFTER[$attempt - 1] ?? self::RETRY_AFTER[count(self::RETRY_AFTER) - 1];
            $this->release($wait);

            return;
        }

        logger()->warning('Could not start server after creation', [
            'server_id' => $server->id,
            'attempts' => $attempt,
            'queued' => $this->job !== null,
            'reason' => $reason,
        ]);
    }
}
