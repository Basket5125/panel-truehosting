<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Pterodactyl\Node;
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

    /** A role at or above this power counts as staff. */
    public const STAFF_POWER = 80;

    /**
     * Users whose rows are left out while the staff filter is on, or an empty
     * array when it is off. Kept as a property so every query shares one lookup.
     *
     * @var array<int, int>
     */
    private array $excludedUserIds = [];

    public function index(Request $request, GeneralSettings $general_settings)
    {
        $this->checkPermission(PaymentController::VIEW_PERMISSION);

        $period = $request->get('period');
        if (!array_key_exists($period, self::PERIODS)) {
            $period = '30d';
        }

        // Staff accounts distort every figure on this page: free servers, test
        // orders, credits granted by hand. Leaving them out is a toggle rather
        // than a rule, because sometimes you do want the real totals.
        $hideStaff = $request->boolean('hide_staff');
        $this->excludedUserIds = $hideStaff ? $this->staffUserIds() : [];

        $days = self::PERIODS[$period];
        $monthly = $days > 90;

        $end = now();
        $start = now()->subDays($days)->startOfDay();
        // The same span again, immediately before, for the comparison figures.
        $previousStart = (clone $start)->subDays($days);

        // Both the per-node table and the recurring-revenue tile are the same
        // sum sliced differently, so the servers are walked once.
        $byNode = $this->serversByNode();

        $paid = fn () => $this->withoutExcluded(
            Payment::query()->where('status', PaymentStatus::PAID),
            'user_id'
        );

        return view('admin.analytics.index')->with([
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'hideStaff' => $hideStaff,
            'staffCount' => count($this->excludedUserIds),
            'staffPower' => self::STAFF_POWER,
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

            'mrrCredits' => $byNode->sum('mrr'),
            'creditsOutstanding' => (int) $this->withoutExcluded(User::query(), 'id')->sum('credits'),
            'revenueForecast' => $this->revenueForecast(),

            'servers' => $this->serverTotals(),
            'serverSeries' => $this->serverSeries($start, $end, $monthly),
            'serversByNode' => $byNode,
            'newServers' => $this->withoutExcluded(Server::whereBetween('created_at', [$start, $end]), 'user_id')->count(),
            'newServersPrevious' => $this->withoutExcluded(Server::whereBetween('created_at', [$previousStart, $start]), 'user_id')->count(),

            'users' => [
                'total' => $this->withoutExcluded(User::query(), 'id')->count(),
                'new' => $this->withoutExcluded(User::whereBetween('created_at', [$start, $end]), 'id')->count(),
                'newPrevious' => $this->withoutExcluded(User::whereBetween('created_at', [$previousStart, $start]), 'id')->count(),
                'withServers' => $this->withoutExcluded(Server::query(), 'user_id')->distinct('user_id')->count('user_id'),
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
     * Ids of every user holding a role at or above the staff power.
     *
     * @return array<int, int>
     */
    private function staffUserIds(): array
    {
        return User::whereHas('roles', fn ($q) => $q->where('power', '>=', self::STAFF_POWER))
            ->pluck('id')
            ->all();
    }

    /**
     * Drop the excluded users from a query. A no-op while the filter is off,
     * so no needless WHERE lands on the common path.
     */
    private function withoutExcluded($query, string $column)
    {
        return empty($this->excludedUserIds)
            ? $query
            : $query->whereNotIn($column, $this->excludedUserIds);
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

        $rows = $this->withoutExcluded(
            Payment::where('status', PaymentStatus::PAID)
                ->where('currency_code', $currency)
                ->whereBetween('created_at', [$from, $to]),
            'user_id'
        )
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

        $created = $this->withoutExcluded(
            Server::whereBetween('created_at', [$from, $to]), 'user_id'
        )
            ->select(DB::raw("DATE_FORMAT(created_at, '{$format}') as bucket"), DB::raw('COUNT(*) as total'))
            ->groupBy('bucket')->pluck('total', 'bucket')->toArray();

        $lost = $this->withoutExcluded(Server::whereNotNull('canceled'), 'user_id')
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
        return $this->withoutExcluded(
            Payment::where('status', PaymentStatus::PAID)
                ->where('currency_code', $this->dominantCurrency())
                ->whereBetween('created_at', [$from, $to]),
            'user_id'
        )
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

        $rows = $this->withoutExcluded(
            Payment::where('status', PaymentStatus::PAID)
                ->where('currency_code', $this->dominantCurrency())
                ->where('created_at', '>=', $from),
            'user_id'
        )
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

    /** @return array<string, int> */
    private function serverTotals(): array
    {
        return [
            'total' => $this->withoutExcluded(Server::query(), 'user_id')->count(),
            'active' => $this->withoutExcluded(Server::whereNull('suspended')->whereNull('canceled'), 'user_id')->count(),
            'suspended' => $this->withoutExcluded(Server::whereNotNull('suspended'), 'user_id')->count(),
            'canceled' => $this->withoutExcluded(Server::whereNotNull('canceled'), 'user_id')->count(),
        ];
    }

    /**
     * What each node carries and earns a month, in credits.
     *
     * Payments are not tied to a node, so the figure is what the servers on it
     * bill per month — the same basis as the recurring revenue tile.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function serversByNode()
    {
        // Counting by (node, product) keeps this to two queries no matter how
        // many servers there are.
        $rows = $this->withoutExcluded(
            Server::whereNull('suspended')->whereNull('canceled'), 'user_id'
        )
            ->select('node_id', 'product_id', DB::raw('COUNT(*) as servers'))
            ->groupBy('node_id', 'product_id')
            ->get();

        $products = Product::whereIn('id', $rows->pluck('product_id')->unique())->get()->keyBy('id');
        $nodes = Node::whereIn('id', $rows->pluck('node_id')->unique())->get()->keyBy('id');

        $totals = [];

        foreach ($rows as $row) {
            // Servers created before the node was recorded group together
            // until `cp:servers:syncnodes` has been run.
            $nodeId = $row->node_id === null ? 0 : (int) $row->node_id;
            $count = (int) $row->servers;
            $product = $products[$row->product_id] ?? null;

            if (!isset($totals[$nodeId])) {
                $totals[$nodeId] = [
                    'name' => $nodeId === 0
                        ? __('Node not recorded')
                        : ($nodes[$nodeId]->name ?? ('#' . $nodeId)),
                    'servers' => 0,
                    'mrr' => 0,
                ];
            }

            $totals[$nodeId]['servers'] += $count;
            $totals[$nodeId]['mrr'] += $product ? (int) round($product->getMonthlyPrice() * $count) : 0;
        }

        return collect($totals)->sortByDesc('mrr')->values();
    }

    /**
     * Products ranked by how many active servers run on them, with the share
     * of monthly credit income each represents.
     */
    private function topProducts()
    {
        $counts = $this->withoutExcluded(
            Server::whereNull('suspended')->whereNull('canceled'), 'user_id'
        )
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
        $rows = $this->withoutExcluded(
            Payment::where('status', PaymentStatus::PAID)
                ->whereBetween('created_at', [$from, $to]),
            'user_id'
        )
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
        $rows = $this->withoutExcluded(
            Payment::where('status', PaymentStatus::PAID)
                ->whereBetween('created_at', [$from, $to]),
            'user_id'
        )
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
        return $this->withoutExcluded(
            Payment::where('status', PaymentStatus::PAID), 'user_id'
        )
            ->select('currency_code', DB::raw('COUNT(*) as c'))
            ->groupBy('currency_code')
            ->orderByDesc('c')
            ->value('currency_code') ?? 'EUR';
    }
}
