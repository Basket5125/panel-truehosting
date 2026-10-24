<?php

namespace App\Console\Commands;

use App\Contracts\GamePanelClient;
use App\Models\Server;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fills in `servers.node_id` for servers created before the column existed.
 *
 * The node is read back from the game panel, one server at a time, because
 * nothing local ever recorded it.
 */
class SyncServerNodesCommand extends Command
{
    protected $signature = 'cp:servers:syncnodes {--all : re-read every server, not only the ones with no node}';

    protected $description = 'Read each server\'s node back from the game panel';

    public function handle(GamePanelClient $client): int
    {
        $query = Server::query()->whereNull('canceled');

        if (!$this->option('all')) {
            $query->whereNull('node_id');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Reading {$total} server(s) from " . $client->panelName() . '...');
        $bar = $this->output->createProgressBar($total);

        $found = 0;
        $failed = 0;

        $query->chunkById(50, function ($servers) use ($client, $bar, &$found, &$failed) {
            foreach ($servers as $server) {
                $bar->advance();

                if (!$server->pterodactyl_id) {
                    $failed++;
                    continue;
                }

                try {
                    $attributes = $client->getServerAttributes($server->pterodactyl_id, true);
                    $nodeId = $attributes['node'] ?? null;

                    if ($nodeId) {
                        $server->forceFill(['node_id' => (int) $nodeId])->saveQuietly();
                        $found++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable $e) {
                    // One unreachable server must not stop the rest.
                    $failed++;
                    logger('cp:servers:syncnodes failed for ' . $server->id . ': ' . $e->getMessage());
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Matched: {$found}");

        if ($failed > 0) {
            $this->warn("Could not resolve: {$failed}");
        }

        return self::SUCCESS;
    }
}
