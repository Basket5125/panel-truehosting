<?php

namespace App\Console\Commands;

use App\Contracts\GamePanelClient;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Nest;
use Illuminate\Console\Command;
use Throwable;

/**
 * Shows the image each egg is stored with next to the one the panel reports,
 * so a server coming up on the wrong runtime can be traced to the sync rather
 * than guessed at.
 */
class EggImagesCommand extends Command
{
    protected $signature = 'cp:eggs:images {--diff : list only the eggs that disagree}';

    protected $description = 'Compare the stored Docker image of each egg with the panel';

    public function handle(GamePanelClient $client): int
    {
        $stored = Egg::all()->keyBy('id');
        $rows = [];
        $differences = 0;

        foreach (Nest::all() as $nest) {
            try {
                $eggs = $client->getEggs($nest);
            } catch (Throwable $e) {
                $this->warn('Could not read eggs for ' . $nest->name . ': ' . $e->getMessage());

                continue;
            }

            foreach ($eggs as $egg) {
                $attributes = $egg['attributes'] ?? $egg;
                $id = $attributes['id'] ?? null;
                $resolved = Egg::resolveDockerImage($attributes);
                $current = $stored[$id]->docker_image ?? '';
                $same = $current === $resolved;

                if (!$same) {
                    $differences++;
                }

                if ($this->option('diff') && $same) {
                    continue;
                }

                $rows[] = [
                    $attributes['name'] ?? ('#' . $id),
                    $current ?: '—',
                    $same ? 'same' : ($resolved ?: '—'),
                ];
            }
        }

        if (empty($rows)) {
            $this->info($this->option('diff') ? 'Every egg matches the panel.' : 'No eggs found.');

            return self::SUCCESS;
        }

        $this->table(['Egg', 'Stored now', 'Panel says'], $rows);

        if ($differences > 0) {
            $this->warn($differences . ' egg(s) would change. Run the catalogue sync in the admin area to apply them.');
        } else {
            $this->info('Every egg matches the panel.');
        }

        return self::SUCCESS;
    }
}
