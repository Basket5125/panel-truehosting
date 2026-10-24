<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Server;
use App\Models\ShopProduct;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting over the tables CtrlPanel already has.
 *
 * Nothing here writes, and no column outside the stock schema is referenced,
 * so a database from an unmodified CtrlPanel works as-is.
 */
class AnalyticsController extends Controller
{
    /** Periods the page can be filtered by: label => days. */
    public const PERIODS = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
        '12m' => 365,
    ];

    public function index(Request $request, GeneralSettings $general_settings)
    {
        $this->checkPermission(PaymentController::VIEW_PERMISSION);

        $period = $request->get('period');
        if (!array_key_exists($period, self::PERIODS)) {
            $period = '30d';
        }

        $days = self::PERIODS[$period];
        $monthly = $days > 90;

        $end = now();
        $start = now()->subDays($days)->startOfDay();
        // The same span again, immediately before, for the comparison figures.
        $previousStart = (clone $start)->subDays($days);

        $paid = fn () => Payment::query()->where('status', PaymentStatus::PAID);

        return view('admin.analytics.index')->with([
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'monthly' => $monthly,
            'start' => $start,
            'end' => $end,

            'revenue' => $this->revenueByCurrency($paid(), $start, $end),
            'revenuePrevious' => $this->revenueByCurrency($paid(), $previousStart, $start),
            'revenueSeries' => $this->revenueSeries($start, $end, $monthly),
            'revenueByMethod' => $this->revenueByMethod($start, $end),
            'revenueByMonth' => $this->revenueByMonth(12),

            'paymentsCount' => $paid()->whereBetween('created_at', [$start, $end])->count(),
            'paymentsCountPrevious' => $paid()->whereBetween('created_at', [$previousStart, $start])->count(),

            'mrrCredits' => $this->monthlyRecurringCredits(),
            'creditsOutstanding' => (int) User::sum('credits'),
            'revenueForecast' => $this->revenueForecast(),

            'servers' => $this->serverTotals(),
            'serverSeries' => $this->serverSeries($start, $end, $monthly),
            'newServers' => Server::whereBetween('created_at', [$start, $end])->count(),
            'newServersPrevious' => Server::whereBetween('created_at', [$previousStart, $start])->count(),

            'users' => [
                'total' => User::count(),
                'new' => User::whereBetween('created_at', [$start, $end])->count(),
                'newPrevious' => User::whereBetween('created_at', [$previousStart, $start])->count(),
                'withServers' => Server::distinct('user_id')->count('user_id'),
            ],

            'topProducts' => $this->topProducts(),
            'topShopProducts' => $this->topShopProducts($start, $end),
            'topCustomers' => $this->topCustomers($start, $end),
            'couponUses' => $paid()->whereBetween('created_at', [$start, $end])
                ->whereNotNull('coupon_code')->count(),

            'credits_display_name' => $general_settings->credits_display_name,
        ]);
    }

    /**
     * Payments can be taken in several currencies; summing them together would
     * be meaningless, so each is reported on its own.
     *
     * @return array<string, int>
     */
    private function revenueByCurrency($query, Carbon $from, Carbon $to): array
    {
        return $query->whereBetween('created_at', [$from, $to])
            ->select('currency_code', DB::raw('SUM(total_price) as total'))
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    /**
     * Revenue per bucket for the chart, in the most used currency.
     *
     * @return array{labels: array<int, string>, data: array<int, float>, currency: string}
     */
    private function revenueSeries(Carbon $from, Carbon $to, bool $monthly): array
    {
        $currency = $this->dominantCurrency();
        $format = $monthly ? '%Y-%m' : '%Y-%m-%d';

        $rows = Payment::where('status', PaymentStatus::PAID)
            ->where('currency_code', $currency)
            ->whereBetween('created_at', [$from, $to])
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$format}') as bucket"),
                DB::raw('SUM(total_price) as total')
            )
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->toArray();

        return $this->fillBuckets($rows, $from, $to, $monthly) + ['currency' => $currency];
    }

    /**
     * Servers created per bucket, next to servers that were cancelled.
     *
     * @return array{labels: array<int, string>, created: array<int, float>, lost: array<int, float>}
     */
    private function serverSeries(Carbon $from, Carbon $to, bool $monthly): array
    {
        $format = $monthly ? '%Y-%m' : '%Y-%m-%d';

        $created = Server::whereBetween('created_at', [$from, $to])
            ->select(DB::raw("DATE_FORMAT(created_at, '{$format}') as bucket"), DB::raw('COUNT(*) as total'))
            ->groupBy('bucket')->pluck('total', 'bucket')->toArray();

        $lost = Server::whereNotNull('canceled')
            ->whereBetween('canceled', [$from, $to])
            ->select(DB::raw("DATE_FORMAT(canceled, '{$format}') as bucket"), DB::raw('COUNT(*) as total'))
            ->groupBy('bucket')->pluck('total', 'bucket')->toArray();

        $a = $this->fillBuckets($created, $from, $to, $monthly);
        $b = $this->fillBuckets($lost, $from, $to, $monthly);

        return ['labels' => $a['labels'], 'created' => $a['data'], 'lost' => $b['data']];
    }

    /**
     * Turns a sparse bucket => value map into a dense, ordered series so the
     * chart does not skip days with no activity.
     *
     * @return array{labels: array<int, string>, data: array<int, float>}
     */
    private function fillBuckets(array $rows, Carbon $from, Carbon $to, bool $monthly): array
    {
        $labels = [];
        $data = [];
        $cursor = (clone $from)->startOfDay();

        while ($cursor <= $to) {
            $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $labels[] = $monthly ? $cursor->format('m.Y') : $cursor->format('d.m');
            $data[] = round(((float) ($rows[$key] ?? 0)) / 1000, 2);

            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** @return array<string, int> */
    private function revenueByMethod(Carbon $from, Carbon $to): array
    {
        return Payment::where('status', PaymentStatus::PAID)
            ->where('currency_code', $this->dominantCurrency())
            ->whereBetween('created_at', [$from, $to])
            ->select('payment_method', DB::raw('SUM(total_price) as total'))
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->pluck('total', 'payment_method')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    /**
     * Revenue for each of the last N calendar months, used by the forecast.
     *
     * @return array<string, int>
     */
    private function revenueByMonth(int $months): array
    {
        $from = now()->subMonths($months - 1)->startOfMonth();

        $rows = Payment::where('status', PaymentStatus::PAID)
            ->where('currency_code', $this->dominantCurrency())
            ->where('created_at', '>=', $from)
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as bucket"), DB::raw('SUM(total_price) as total'))
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->toArray();

        $out = [];
        $cursor = clone $from;
        while ($cursor <= now()) {
            $key = $cursor->format('Y-m');
            $out[$key] = (int) ($rows[$key] ?? 0);
            $cursor->addMonth();
        }

        return $out;
    }

    /**
     * A deliberately plain forecast: the mean of the last three *finished*
     * months. It is labelled as an estimate in the view rather than dressed up
     * as a prediction.
     *
     * @return array{value: int, basis: int}
     */
    private function revenueForecast(): array
    {
        $months = $this->revenueByMonth(4);
        array_pop($months); // drop the current, incomplete month

        $months = array_values($months);
        if (count($months) === 0) {
            return ['value' => 0, 'basis' => 0];
        }

        return ['value' => (int) round(array_sum($months) / count($months)), 'basis' => count($months)];
    }

    /**
     * What the active servers bill per month, in credits.
     */
    private function monthlyRecurringCredits(): int
    {
        $total = 0;

        Server::with('product')
            ->whereNull('suspended')
            ->whereNull('canceled')
            ->chunk(500, function ($servers) use (&$total) {
                foreach ($servers as $server) {
                    if ($server->product) {
                        $total += $server->product->getMonthlyPrice();
                    }
                }
            });

        return (int) round($total);
    }

    /** @return array<string, int> */
    private function serverTotals(): array
    {
        return [
            'total' => Server::count(),
            'active' => Server::whereNull('suspended')->whereNull('canceled')->count(),
            'suspended' => Server::whereNotNull('suspended')->count(),
            'canceled' => Server::whereNotNull('canceled')->count(),
        ];
    }

    /**
     * Products ranked by how many active servers run on them, with the share
     * of monthly credit income each represents.
     */
    private function topProducts()
    {
        $counts = Server::whereNull('suspended')->whereNull('canceled')
            ->select('product_id', DB::raw('COUNT(*) as servers'))
            ->groupBy('product_id')
            ->orderByDesc('servers')
            ->limit(10)
            ->pluck('servers', 'product_id');

        return Product::whereIn('id', $counts->keys())->get()
            ->map(function (Product $product) use ($counts) {
                $servers = (int) $counts[$product->id];

                return [
                    'name' => $product->name,
                    'servers' => $servers,
                    'billing_period' => $product->billing_period,
                    'mrr' => (int) round($product->getMonthlyPrice() * $servers),
                ];
            })
            ->sortByDesc('servers')
            ->values();
    }

    /** Store items ranked by paid orders in the period. */
    private function topShopProducts(Carbon $from, Carbon $to)
    {
        $rows = Payment::where('status', PaymentStatus::PAID)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('shop_item_product_id')
            ->select(
                'shop_item_product_id',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(total_price) as total')
            )
            ->groupBy('shop_item_product_id')
            ->orderByDesc('orders')
            ->limit(10)
            ->get();

        $products = ShopProduct::whereIn('id', $rows->pluck('shop_item_product_id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'name' => $products[$row->shop_item_product_id]->display ?? $row->shop_item_product_id,
            'type' => $products[$row->shop_item_product_id]->type ?? '',
            'orders' => (int) $row->orders,
            'total' => (int) $row->total,
        ]);
    }

    /** Customers ranked by what they actually paid in the period. */
    private function topCustomers(Carbon $from, Carbon $to)
    {
        $rows = Payment::where('status', PaymentStatus::PAID)
            ->whereBetween('created_at', [$from, $to])
            ->select('user_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(total_price) as total'))
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'id' => $row->user_id,
            'name' => $users[$row->user_id]->name ?? ('#' . $row->user_id),
            'orders' => (int) $row->orders,
            'total' => (int) $row->total,
        ]);
    }

    /** The currency most payments are made in; charts are drawn in it. */
    private function dominantCurrency(): string
    {
        return Payment::where('status', PaymentStatus::PAID)
            ->select('currency_code', DB::raw('COUNT(*) as c'))
            ->groupBy('currency_code')
            ->orderByDesc('c')
            ->value('currency_code') ?? 'EUR';
    }
}
