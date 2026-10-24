<?php

namespace App\Console\Commands;

use App\Models\CustomOrder;
use App\Models\ShopProduct;
use Illuminate\Console\Command;

/**
 * Removes configured orders that were never paid for.
 *
 * Each pending order carries a throwaway shop product holding the price that
 * was quoted. Left alone, that checkout link would stay payable at the old
 * price forever, so both are dropped once the quote is stale.
 */
class CleanupCustomOrders extends Command
{
    protected $signature = 'cp:customorders:cleanup {--hours=24 : How old a pending order may be}';

    protected $description = 'Drop unpaid configured orders and the throwaway products behind them';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')));

        $orders = CustomOrder::where('status', CustomOrder::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->get();

        $removed = 0;

        foreach ($orders as $order) {
            if ($order->shop_product_id) {
                // Only ever the hidden product generated for this order.
                ShopProduct::where('id', $order->shop_product_id)
                    ->where('disabled', true)
                    ->delete();
            }

            $order->delete();
            $removed++;
        }

        $this->info($removed . ' stale configured order(s) removed.');

        return self::SUCCESS;
    }
}
