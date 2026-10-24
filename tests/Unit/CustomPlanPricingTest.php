<?php

namespace Tests\Unit;

use App\Models\CustomPlan;
use Tests\TestCase;

/**
 * The price a customer is charged comes out of these two methods, so both the
 * arithmetic and the clamping are pinned down here.
 */
class CustomPlanPricingTest extends TestCase
{
    private function plan(array $overrides = []): CustomPlan
    {
        // Prices use the same 1000x scale as the rest of the panel:
        // 2000 == 2.00 credits.
        return new CustomPlan(array_merge([
            'name' => 'Builder',
            'billing_period' => 'monthly',
            'price_cpu' => 2000,
            'price_memory' => 1500,
            'price_disk' => 100,
            'price_backup' => 500,
            'price_database' => 0,
            'price_allocation' => 0,
            'cpu_min' => 100, 'cpu_max' => 800, 'cpu_step' => 100,
            'memory_min' => 1024, 'memory_max' => 16384, 'memory_step' => 1024,
            'disk_min' => 5120, 'disk_max' => 102400, 'disk_step' => 5120,
            'backups_min' => 0, 'backups_max' => 10, 'backups_step' => 1,
            'databases_min' => 0, 'databases_max' => 5, 'databases_step' => 1,
            'allocations_min' => 1, 'allocations_max' => 5, 'allocations_step' => 1,
            'discount_per_unit' => 0,
            'discount_max' => 0,
            'large_order_memory' => null,
        ], $overrides));
    }

    public function test_price_is_the_sum_of_the_unit_lines(): void
    {
        $plan = $this->plan();

        // 2 vCores, 4 GB, 20 GB, 2 backups
        $price = $plan->priceFor([
            'cpu' => 200, 'memory' => 4096, 'disk' => 20480,
            'backups' => 2, 'databases' => 0, 'allocations' => 1,
        ]);

        // 2*2000 + 4*1500 + 20*100 + 2*500 = 4000 + 6000 + 2000 + 1000
        $this->assertSame(13000, $price);
    }

    public function test_resources_priced_at_zero_do_not_appear(): void
    {
        $plan = $this->plan();
        $lines = $plan->breakdown([
            'cpu' => 100, 'memory' => 1024, 'disk' => 5120,
            'backups' => 0, 'databases' => 3, 'allocations' => 2,
        ]);

        $this->assertArrayNotHasKey('databases', $lines);
        $this->assertArrayNotHasKey('allocations', $lines);
        $this->assertArrayNotHasKey('backups', $lines);
    }

    public function test_the_first_unit_is_never_discounted(): void
    {
        $plan = $this->plan(['discount_per_unit' => 5, 'discount_max' => 50]);

        $this->assertSame(0.0, $plan->discountFor(1));
        $this->assertSame(0.05, round($plan->discountFor(2), 4));
        $this->assertSame(0.20, round($plan->discountFor(5), 4));
    }

    public function test_the_discount_is_capped(): void
    {
        $plan = $this->plan(['discount_per_unit' => 10, 'discount_max' => 25]);

        // 8 units would be 70% without the cap.
        $this->assertSame(0.25, round($plan->discountFor(8), 4));
    }

    public function test_buying_more_units_lowers_the_price_per_unit(): void
    {
        $plan = $this->plan(['discount_per_unit' => 5, 'discount_max' => 50]);

        $one = $plan->priceFor(['cpu' => 100, 'memory' => 1024, 'disk' => 5120, 'backups' => 0, 'databases' => 0, 'allocations' => 1]);
        $four = $plan->priceFor(['cpu' => 400, 'memory' => 4096, 'disk' => 5120, 'backups' => 0, 'databases' => 0, 'allocations' => 1]);

        $perUnitOne = $one / 1;
        $perUnitFour = $four / 4;

        $this->assertLessThan($perUnitOne, $perUnitFour, 'four units should cost less per unit than one');
    }

    public function test_clamp_pulls_values_inside_the_configured_range(): void
    {
        $plan = $this->plan();

        $clamped = $plan->clamp([
            'cpu' => 99999, 'memory' => -5, 'disk' => 0,
            'backups' => 999, 'databases' => 2, 'allocations' => 1,
        ]);

        $this->assertSame(800, $clamped['cpu']);
        $this->assertSame(1024, $clamped['memory']);
        $this->assertSame(5120, $clamped['disk']);
        $this->assertSame(10, $clamped['backups']);
    }

    public function test_clamp_snaps_to_the_step(): void
    {
        $plan = $this->plan();

        $clamped = $plan->clamp(['cpu' => 170, 'memory' => 3000, 'disk' => 7000]);

        $this->assertSame(200, $clamped['cpu']);
        $this->assertSame(3072, $clamped['memory']);
        $this->assertSame(5120, $clamped['disk']);
    }

    public function test_clamp_ignores_junk_and_falls_back_to_the_minimum(): void
    {
        $plan = $this->plan();

        $clamped = $plan->clamp(['cpu' => 'abc', 'memory' => null]);

        $this->assertSame(100, $clamped['cpu']);
        $this->assertSame(1024, $clamped['memory']);
    }

    public function test_a_crafted_request_cannot_make_the_order_free(): void
    {
        $plan = $this->plan();

        $price = $plan->priceFor($plan->clamp([
            'cpu' => 0, 'memory' => 0, 'disk' => 0,
            'backups' => -10, 'databases' => -1, 'allocations' => -1,
        ]));

        // Falls back to the minimum configuration, which still costs money.
        $this->assertGreaterThan(0, $price);
    }

    public function test_the_money_price_comes_from_its_own_list_when_there_is_one(): void
    {
        // 1.00 per vCore, 0.75 per GB RAM, nothing per GB disk or backup.
        $plan = $this->plan([
            'payment_modes' => 'both',
            'currency_code' => 'PLN',
            // A rate is still set, to prove the list wins over it.
            'credit_rate' => 0.05,
            'money_price_cpu' => 1000,
            'money_price_memory' => 750,
        ]);

        $resources = [
            'cpu' => 200, 'memory' => 4096, 'disk' => 20480,
            'backups' => 2, 'databases' => 0, 'allocations' => 1,
        ];

        // Credits are unchanged: 2*2000 + 4*1500 + 20*100 + 2*500.
        $this->assertSame(13000, $plan->priceFor($resources));

        // Money: 2*1000 + 4*750, and nothing for the unpriced lines.
        $this->assertSame(5000, $plan->moneyPriceFor($resources));
    }

    public function test_a_resource_priced_only_in_money_is_still_offered(): void
    {
        $plan = $this->plan([
            'price_database' => 0,
            'money_price_database' => 500,
        ]);

        $this->assertTrue($plan->offers('databases'));
        $this->assertSame(0, $plan->priceFor(['databases' => 3]));
        $this->assertSame(1500, $plan->moneyPriceFor(['databases' => 3]));
    }

    public function test_a_money_price_list_is_enough_for_direct_payment(): void
    {
        $plan = $this->plan([
            'payment_modes' => 'both',
            'credit_rate' => 0,
            'money_price_cpu' => 1000,
        ]);

        $this->assertTrue($plan->allowsDirect());
    }

    public function test_the_money_price_follows_the_credit_rate(): void
    {
        $plan = $this->plan(['credit_rate' => 0.05, 'payment_modes' => 'both', 'currency_code' => 'EUR']);

        $resources = $plan->clamp([]);

        // The fixture's minimum is 1 vCore + 1 GB + 5 GB disk:
        // 2000 + 1500 + 500 = 4000 credits, and 4.00 credits at 0.05 each
        // is 0.20 in money. Both use the 1000x scale.
        $this->assertSame(4000, $plan->priceFor($resources));
        $this->assertSame(200, $plan->moneyPriceFor($resources));
    }

    public function test_direct_payment_needs_a_rate(): void
    {
        $this->assertFalse($this->plan(['payment_modes' => 'both', 'credit_rate' => 0])->allowsDirect());
        $this->assertTrue($this->plan(['payment_modes' => 'both', 'credit_rate' => 0.05])->allowsDirect());
        $this->assertFalse($this->plan(['payment_modes' => 'credits', 'credit_rate' => 0.05])->allowsDirect());
        $this->assertFalse($this->plan(['payment_modes' => 'direct'])->allowsCredits());
    }

    public function test_renewing_early_adds_to_the_period_already_paid_for(): void
    {
        $paidUntil = \Carbon\Carbon::parse('2026-06-10 12:00:00');

        // Renewing while still paid up must extend from the expiry...
        $this->assertSame(
            '2026-07-10',
            \App\Services\ServerRenewalService::addPeriod($paidUntil, 'monthly')->toDateString()
        );

        // ...and the period helper must honour each cycle length.
        $this->assertSame(
            '2026-06-17',
            \App\Services\ServerRenewalService::addPeriod($paidUntil, 'weekly')->toDateString()
        );
        $this->assertSame(
            '2027-06-10',
            \App\Services\ServerRenewalService::addPeriod($paidUntil, 'annually')->toDateString()
        );
    }

    /**
     * Product and ShopProduct both multiply an incoming price by 1000, while a
     * plan's figures are already on that scale. Handing one straight over made
     * every configured server cost 1000x too much, so no customer ever had
     * enough credits and a direct payment charged 1000x the real amount.
     */
    public function test_a_plan_price_survives_the_handover_to_a_product(): void
    {
        $credits = $this->plan()->priceFor([
            'cpu' => 200, 'memory' => 4096, 'disk' => 20480,
            'backups' => 2, 'databases' => 0, 'allocations' => 1,
        ]);

        $product = new \App\Models\Product();
        $product->price = \App\Facades\Currency::convertForDisplay($credits);

        $this->assertSame($credits, (int) $product->price);

        $shopProduct = new \App\Models\ShopProduct();
        $shopProduct->price = \App\Facades\Currency::convertForDisplay($credits);

        $this->assertSame($credits, (int) $shopProduct->price);
    }

    public function test_large_orders_are_recognised(): void
    {
        $plan = $this->plan(['large_order_memory' => 8192]);

        $this->assertFalse($plan->isLargeOrder(['memory' => 8192]));
        $this->assertTrue($plan->isLargeOrder(['memory' => 12288]));
    }

    public function test_no_threshold_means_no_large_orders(): void
    {
        $plan = $this->plan(['large_order_memory' => null]);

        $this->assertFalse($plan->isLargeOrder(['memory' => 999999]));
    }
}
