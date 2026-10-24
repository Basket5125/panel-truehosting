<?php

namespace App\Http\Controllers;

use App\Enums\BillingPriority;
use App\Facades\Currency;
use App\Models\CustomOrder;
use App\Models\CustomPlan;
use App\Models\ShopProduct;
use App\Services\CustomOrderProvisioner;
use App\Models\Pterodactyl\Egg;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Enum;
use Throwable;

/**
 * Customer side of the configurable plans: pick the resources, see the price,
 * order. The resulting server is provisioned through the ordinary creation
 * service, so billing, suspension and upgrades keep working unchanged.
 */
class CustomPlanOrderController extends Controller
{
    public const CREATE_PERMISSION = 'user.server.create';

    public function __construct(private GeneralSettings $generalSettings)
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $plans = CustomPlan::where('disabled', false)->orderBy('name')->get();

        if ($plans->count() === 1) {
            return redirect()->route('plans.show', $plans->first()->id);
        }

        return view('plans.index', [
            'plans' => $plans,
            'credits_display_name' => $this->generalSettings->credits_display_name,
        ]);
    }

    public function show(CustomPlan $customplan)
    {
        abort_if($customplan->disabled, 404);

        $eggs = $customplan->eggs()->with('nest')->get();
        $defaults = $customplan->clamp([]);

        return view('plans.show', [
            'plan' => $customplan,
            'eggs' => $eggs,
            'defaults' => $defaults,
            'breakdown' => $customplan->breakdown($defaults),
            'price' => $customplan->priceFor($defaults),
            'credits_display_name' => $this->generalSettings->credits_display_name,
        ]);
    }

    /**
     * Live price for a configuration. The figures are always recomputed from
     * the stored plan, never taken from the request.
     */
    public function quote(Request $request, CustomPlan $customplan): JsonResponse
    {
        abort_if($customplan->disabled, 404);

        $resources = $customplan->clamp($request->all());

        return response()->json([
            'resources' => $resources,
            'breakdown' => $customplan->breakdown($resources),
            'price' => $customplan->priceFor($resources),
            'money_breakdown' => $customplan->breakdown($resources, true),
            'money_price' => $customplan->moneyPriceFor($resources),
            'currency_code' => $customplan->currency_code,
            'large_order' => $customplan->isLargeOrder($resources),
        ]);
    }

    public function order(Request $request, CustomPlan $customplan, CustomOrderProvisioner $provisioner): RedirectResponse
    {
        $this->checkPermission(self::CREATE_PERMISSION);
        abort_if($customplan->disabled, 404);

        $request->validate([
            'name' => 'required|string|max:191',
            'egg' => 'required|integer',
            // When the plan offers both, the customer has to say which; there
            // is no sensible default between spending credits and being sent
            // to a payment page.
            'payment_mode' => [
                $customplan->allowsCredits() && $customplan->allowsDirect() ? 'required' : 'nullable',
                'in:credits,direct',
            ],
            'billing_priority' => ['nullable', new Enum(BillingPriority::class)],
            'egg_variables' => 'nullable|string',
        ]);

        $lockKey = 'custom_plan_order_' . Auth::id();
        if (Cache::has($lockKey)) {
            return back()->with('error', __('Please wait a moment before creating another server.'));
        }
        Cache::put($lockKey, true, 5);

        $user = $request->user();

        // The egg has to be one this plan actually offers.
        $egg = $customplan->eggs()->where('eggs.id', (int) $request->input('egg'))->first();

        if (!$egg) {
            Cache::forget($lockKey);

            return back()->with('error', __('That software is not available in this plan.'));
        }

        // Never price from the request body.
        $resources = $customplan->clamp($request->all());

        // Only a mode the plan actually permits, whatever was submitted.
        $mode = $request->input('payment_mode');
        if (!in_array($mode, ['credits', 'direct'], true)
            || ($mode === 'credits' && !$customplan->allowsCredits())
            || ($mode === 'direct' && !$customplan->allowsDirect())) {
            $mode = $customplan->allowsCredits() ? 'credits' : 'direct';
        }

        try {
            if ($mode === 'direct') {
                $order = $this->startFirstPeriodPayment($customplan, $user, $resources, $egg, $request->input('name'));
                Cache::forget($lockKey);

                return redirect()->route('checkout', $order->shop_product_id);
            }

            $provisioner->create(
                $customplan,
                $user,
                $resources,
                $egg,
                $request->input('name'),
                'credits',
                $request->input('billing_priority'),
                $request->input('egg_variables')
            );

            Cache::forget($lockKey);

            return redirect()->route('servers.index')
                ->with('success', __('Your server is being created.'));
        } catch (Throwable $e) {
            Cache::forget($lockKey);

            Log::error('Custom plan order failed', [
                'plan' => $customplan->id,
                'user' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Park the configuration and send the customer to the checkout. The server
     * is only created once the payment has actually arrived.
     *
     * @param  array<string, int>  $resources
     */
    private function startFirstPeriodPayment(CustomPlan $plan, $user, array $resources, Egg $egg, string $name): CustomOrder
    {
        $credits = $plan->priceFor($resources);
        $money = $plan->moneyPriceFor($resources);

        if ($money <= 0) {
            throw new \Exception(__('This plan cannot be paid for directly.'));
        }

        $shopProduct = ShopProduct::create([
            'type' => 'Server',
            // ShopProduct::price multiplies by 1000 on the way in and $money
            // is already on that scale, so it is handed over as a plain figure.
            'price' => Currency::convertForDisplay($money),
            'display' => $plan->name . ' · ' . $name,
            'description' => __('First :period of :name', [
                'period' => $plan->billing_period,
                'name' => $name,
            ]),
            'currency_code' => $plan->currency_code,
            'quantity' => 0,
            // Never offered in the store; it exists for this one payment.
            'disabled' => true,
        ]);

        return CustomOrder::create([
            'user_id' => $user->id,
            'custom_plan_id' => $plan->id,
            'egg_id' => $egg->id,
            'name' => $name,
            'resources' => $resources,
            'price_credits' => $credits,
            'price_money' => $money,
            'currency_code' => $plan->currency_code,
            'shop_product_id' => $shopProduct->id,
            'status' => CustomOrder::STATUS_PENDING,
            'kind' => CustomOrder::KIND_FIRST,
        ]);
    }
}
