<?php

namespace App\Services;

use App\Contracts\GamePanelClient;
use App\Models\Server;
use App\Models\User;
use App\Models\Product;
use App\Models\Pterodactyl\Node;
use App\Settings\PterodactylSettings;
use Carbon\CarbonInterval;

class ServerUpgradeService
{
    private PterodactylSettings $pterodactylSettings;
    private GamePanelClient $pterodactylClient;

    /**
     * Whether the last upgrade managed to restart the server. False means the
     * limits are applied but will only take effect once the customer restarts
     * it themselves.
     */
    public bool $restarted = true;

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $this->pterodactylSettings = app(PterodactylSettings::class);
        $this->pterodactylClient = app(GamePanelClient::class);
        // No parameters: the binding builds the configured driver by itself,
        // and passing any makes the container skip a bound instance, which
        // quietly defeated every attempt to stand in for the panel.
    }

    /**
     * Handle the server creation process.
     *
     * @param User $user
     * @param Product $product
     * @param Server $server
     * @return Server
     * 
     * @throws \Exception
     */
    public function handle(User $user, Product $product, Server $server): Server
    {
        try {
            $this->validateAndPrepare($user, $product, $server);

            $pterodactylServer = $this->pterodactylClient->getServerAttributes($server->pterodactyl_id);

            $pterodactylServerNodeId = $pterodactylServer['relationships']['node']['attributes']['id'];
            $node = Node::findOrFail($pterodactylServerNodeId);

            // Check if the new product can be applied to the server.
            $requiredMemory = $product->memory - $server->product->memory;
            $requiredDisk = $product->disk - $server->product->disk;

            if (!$this->pterodactylClient->checkNodeResources($node, $requiredMemory, $requiredDisk)) {
                throw new \Exception('Insufficient resources on the node to upgrade the server.', 422);
            }

            $pterodactylServerAllocation = $pterodactylServer['allocation'];

            $updateServerResponse = $this->pterodactylClient->updateServerBuild($server->pterodactyl_id, $pterodactylServerAllocation, $product);
            
            if ($updateServerResponse->failed()) {
                logger()->error('Failed to update server on the game panel', [
                    'pterodactyl_id' => $server->pterodactyl_id,
                    'status' => $updateServerResponse->status(),
                    'error' => $updateServerResponse->body(),
                ]);

                // The upgrade did not happen, so give the money back and leave
                // the server exactly as it was. It used to be deleted here,
                // which destroyed a working server over a failed request.
                $this->refund($user, $product, $server);

                throw new \Exception(
                    'Could not apply the new limits on the game panel. Nothing was changed.'
                );
            }

            // The limits are on the panel now, so the upgrade is recorded
            // whatever happens next.
            $server->update([
                'product_id' => $product->id,
                'last_billed' => now(),
                'canceled' => null
            ]);

            // Restarting is how the new limits take effect. A panel that
            // refuses it has not undone the upgrade, so this is reported to the
            // caller instead of being thrown.
            $this->restarted = $this->tryRestart($server);

            return $server;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Restart a server, reporting whether it worked instead of throwing.
     */
    private function tryRestart(Server $server): bool
    {
        try {
            $response = $this->pterodactylClient->powerAction($server, 'restart');

            if ($response->successful()) {
                return true;
            }

            logger()->warning('Could not restart the server after an upgrade', [
                'server_id' => $server->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            logger()->warning('Restart after an upgrade threw', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Undo what validateAndPrepare() charged, when the upgrade cannot go ahead.
     */
    private function refund(User $user, Product $product, Server $server): void
    {
        $charged = $this->chargeFor($product, $server);

        if ($charged > 0) {
            $user->increment('credits', $charged);
        } elseif ($charged < 0) {
            $user->decrement('credits', abs($charged));
        }
    }

    /**
     * What this upgrade costs: the new product less the unused part of the old
     * one. Rounded to a whole unit so that taking it and giving it back cancel
     * out exactly.
     */
    private function chargeFor(Product $product, Server $server): int
    {
        $billingPeriodSeconds = $this->getSecondsFromBillingPeriod($product);
        $timeUsed = now()->diffInSeconds($server->last_billed, true);
        $refundAmount = $server->product->price - ($server->product->price * ($timeUsed / $billingPeriodSeconds));

        return (int) round($product->price - $refundAmount);
    }

    private function validateAndPrepare(User $user, Product $product, Server $server): void
    {
        // Check if user has enough credits to upgrade the server.
        $finalPrice = $this->chargeFor($product, $server);

        if ($user->credits < $finalPrice) {
            throw new \Exception('Insufficient credits to upgrade the server.', 422);
        }

        // Refund the user for the unused time on the current product.
        if ($finalPrice > 0) {
            $user->decrement('credits', $finalPrice);
        } elseif ($finalPrice < 0) {
            $user->increment('credits', abs($finalPrice));
        }
    }

    private function getSecondsFromBillingPeriod(Product $product): int
    {
        return match ($product->billing_period) {
            'hourly' => CarbonInterval::hour()->totalSeconds,
            'daily' => CarbonInterval::day()->totalSeconds,
            'weekly' => CarbonInterval::week()->totalSeconds,
            'monthly' => CarbonInterval::month()->totalSeconds,
            'quarterly' => CarbonInterval::months(3)->totalSeconds,
            'half-annually' => CarbonInterval::months(6)->totalSeconds,
            'annually' => CarbonInterval::year()->totalSeconds,
            default => CarbonInterval::hour()->totalSeconds,
        };
    }
}
