<?php

namespace App\Services;

use App\Models\CustomOrder;
use App\Models\CustomPlan;
use App\Models\Product;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Node;
use App\Models\Server;
use App\Models\User;
use Exception;

/**
 * Turns a configured plan into a real server: materialises the configuration
 * as a Product so the rest of the panel can bill it, then provisions through
 * the ordinary creation service.
 */
class CustomOrderProvisioner
{
    public function __construct(private ServerCreationService $creator)
    {
    }

    /**
     * Provision a stored order whose first period has been paid for.
     *
     * @throws Exception
     */
    public function provision(CustomOrder $order): Server
    {
        $plan = $order->plan;
        $user = $order->user;

        if (!$plan || !$user) {
            throw new Exception('Order is missing its plan or user.');
        }

        $egg = $plan->eggs()->where('eggs.id', $order->egg_id)->first();

        if (!$egg) {
            throw new Exception('The chosen software is no longer part of this plan.');
        }

        $server = $this->create(
            $plan,
            $user,
            $order->resources,
            $egg,
            $order->name,
            'direct',
            null,
            null
        );

        $order->update([
            'status' => CustomOrder::STATUS_PROVISIONED,
            'server_id' => $server->id,
        ]);

        return $server;
    }

    /**
     * @param  array<string, int>  $resources  already clamped
     *
     * @throws Exception
     */
    public function create(
        CustomPlan $plan,
        User $user,
        array $resources,
        Egg $egg,
        string $name,
        string $paymentMode,
        ?string $billingPriority,
        ?string $eggVariables
    ): Server {
        $node = $plan->pickNode($resources);

        if (!$node) {
            throw new Exception(__('No node can take this configuration right now. Try a smaller one or contact support.'));
        }

        $product = $this->buildProduct($plan, $resources, $plan->priceFor($resources), $egg, $node);

        try {
            $server = $this->creator->handle($user, $product, [
                'name' => $name,
                'egg_id' => $egg->id,
                'location_id' => $node->location_id,
                'billing_priority' => $billingPriority,
                'egg_variables' => $eggVariables,
                // Direct mode has already been paid in money, so no credits
                // may be required or taken here.
                'skip_credits' => $paymentMode === 'direct',
            ]);
        } catch (\Throwable $e) {
            // A product with no server behind it is just clutter.
            if ($product->servers()->count() === 0) {
                $product->delete();
            }

            throw $e;
        }

        $server->payment_mode = $paymentMode;

        // A directly paid server carries its own expiry; a credit-billed one
        // keeps using last_billed as it always has.
        if ($paymentMode === 'direct') {
            $server->paid_until = ServerRenewalService::addPeriod(now(), $plan->billing_period);
        }

        $server->save();

        return $server;
    }

    /**
     * @param  array<string, int>  $resources
     */
    private function buildProduct(CustomPlan $plan, array $resources, int $price, Egg $egg, Node $node): Product
    {
        $product = Product::create([
            'custom_plan_id' => $plan->id,
            'name' => $plan->name . ' · ' . round($resources['cpu'] / 100, 1) . ' vCPU / '
                . round($resources['memory'] / 1024, 1) . ' GB RAM',
            'description' => __('Configured plan'),
            'price' => $price,
            'memory' => $resources['memory'],
            'cpu' => $resources['cpu'],
            'swap' => $plan->swap,
            'disk' => $resources['disk'],
            'io' => $plan->io,
            'databases' => $resources['databases'],
            'backups' => $resources['backups'],
            'allocations' => $resources['allocations'],
            'serverlimit' => 0,
            'oom_killer' => $plan->oom_killer,
            'billing_period' => $plan->billing_period,
            'minimum_credits' => $plan->minimum_credits,
            'disabled' => true,
        ]);

        $product->eggs()->attach($egg->id);
        // Only the node chosen by priority, so the creation service deploys there.
        $product->nodes()->attach($node->id);

        return $product;
    }
}
