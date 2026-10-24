<?php

namespace App\Services;

use App\Models\CustomOrder;
use App\Models\Server;
use App\Models\ShopProduct;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Extends a directly billed server by one period, paid either from credits or
 * with money through the ordinary checkout.
 */
class ServerRenewalService
{
    /**
     * Add one billing period to a date.
     */
    public static function addPeriod(Carbon $from, string $period): Carbon
    {
        return match ($period) {
            'hourly' => $from->copy()->addHour(),
            'daily' => $from->copy()->addDay(),
            'weekly' => $from->copy()->addWeek(),
            'quarterly' => $from->copy()->addMonths(3),
            'half-annually' => $from->copy()->addMonths(6),
            'annually' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }

    /**
     * Renewing early should add to the period already paid for, not throw it
     * away, so the later of "now" and the current expiry is used.
     */
    private function nextExpiry(Server $server): Carbon
    {
        $current = $server->paid_until ? Carbon::parse($server->paid_until) : now();
        $base = $current->isFuture() ? $current : now();

        return self::addPeriod($base, $server->product->billing_period ?? 'monthly');
    }

    /**
     * Take the price out of the user's credits and move the expiry forward.
     *
     * @throws Exception
     */
    public function renewWithCredits(Server $server, User $user): Carbon
    {
        $price = (int) round($server->product->price);

        return DB::transaction(function () use ($server, $user, $price) {
            // Lock the row so two clicks cannot pay once.
            $fresh = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if ($fresh->credits < $price) {
                throw new Exception(__('You do not have enough credits to renew this server.'));
            }

            $fresh->decrement('credits', $price);

            $until = $this->nextExpiry($server);
            $server->paid_until = $until;
            $server->last_billed = now();
            $server->save();

            activity()
                ->performedOn($server)
                ->causedBy($fresh)
                ->log('renewed server from credits until ' . $until->toDateTimeString());

            return $until;
        });
    }

    /**
     * Build the throwaway shop product the checkout needs, plus the order row
     * that lets the completed payment be traced back here.
     */
    public function prepareDirectPayment(Server $server, User $user): CustomOrder
    {
        $plan = $server->product->customPlan;

        if (!$plan || !$plan->allowsDirect()) {
            throw new Exception(__('This server cannot be paid for directly.'));
        }

        $credits = (int) round($server->product->price);
        $money = (int) round($credits * (float) $plan->credit_rate);

        if ($money <= 0) {
            throw new Exception(__('This server cannot be paid for directly.'));
        }

        $shopProduct = ShopProduct::create([
            'type' => 'Server renewal',
            'price' => $money,
            'display' => $server->name,
            'description' => __('Renewal of :server for one :period', [
                'server' => $server->name,
                'period' => $server->product->billing_period,
            ]),
            'currency_code' => $plan->currency_code,
            'quantity' => 0,
            // Never offered in the store; it exists for this one payment.
            'disabled' => true,
        ]);

        return CustomOrder::create([
            'user_id' => $user->id,
            'custom_plan_id' => $plan->id,
            'egg_id' => 0,
            'name' => $server->name,
            'resources' => [],
            'price_credits' => $credits,
            'price_money' => $money,
            'currency_code' => $plan->currency_code,
            'shop_product_id' => $shopProduct->id,
            'server_id' => $server->id,
            'status' => CustomOrder::STATUS_PENDING,
            'kind' => CustomOrder::KIND_RENEWAL,
        ]);
    }

    /**
     * Called once the money for a renewal has arrived.
     */
    public function applyPaidRenewal(CustomOrder $order): void
    {
        $server = $order->server;

        if (!$server) {
            $order->update(['status' => CustomOrder::STATUS_FAILED, 'failure_reason' => 'server missing']);

            return;
        }

        $until = $this->nextExpiry($server);

        $server->paid_until = $until;
        $server->last_billed = now();
        $server->save();

        $order->update(['status' => CustomOrder::STATUS_RENEWED]);

        activity()
            ->performedOn($server)
            ->log('renewed server by payment until ' . $until->toDateTimeString());
    }
}
