<?php

namespace App\Models;

use App\Contracts\GamePanelClient;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Node;
use Hidehalo\Nanoid\Client;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Throwable;

/**
 * A plan the customer configures themselves: the admin sets a price per unit,
 * the customer picks how many units, and the total is derived from that.
 */
class CustomPlan extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'disabled' => 'boolean',
        'oom_killer' => 'boolean',
    ];

    /** The resources a customer can choose, and what one unit means. */
    public const RESOURCES = [
        'cpu' => ['price' => 'price_cpu', 'money' => 'money_price_cpu', 'per' => 100],       // 100% = 1 vCore
        'memory' => ['price' => 'price_memory', 'money' => 'money_price_memory', 'per' => 1024], // MB per GB
        'disk' => ['price' => 'price_disk', 'money' => 'money_price_disk', 'per' => 1024],
        'backups' => ['price' => 'price_backup', 'money' => 'money_price_backup', 'per' => 1],
        'databases' => ['price' => 'price_database', 'money' => 'money_price_database', 'per' => 1],
        'allocations' => ['price' => 'price_allocation', 'money' => 'money_price_allocation', 'per' => 1],
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function (CustomPlan $plan) {
            $plan->{$plan->getKeyName()} = (new Client())->generateId(21);
        });

        // The sidebar caches whether any plan is published.
        $forget = fn () => cache()->forget('th.has_custom_plans');
        static::saved($forget);
        static::deleted($forget);
    }

    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'custom_plan_node')
            ->withPivot(['priority', 'allow_large']);
    }

    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class, 'custom_plan_egg');
    }

    /* ---------------------------------------------------------------------
     | Configuration
     * ------------------------------------------------------------------- */

    /**
     * Force a submitted configuration back inside the plan's own bounds.
     *
     * Everything the customer sends is treated as hostile: values are clamped
     * to the configured range and snapped to the step, so the price can never
     * be driven down by a crafted request.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int>
     */
    public function clamp(array $input): array
    {
        $out = [];

        foreach (array_keys(self::RESOURCES) as $resource) {
            $min = (int) $this->{$resource . '_min'};
            $max = (int) $this->{$resource . '_max'};
            $step = max(1, (int) ($this->{$resource . '_step'} ?? 1));

            $value = (int) ($input[$resource] ?? $min);
            $value = max($min, min($max, $value));

            // Snap to the nearest step above the minimum, then clamp again so
            // rounding can never push the value past the maximum.
            $value = $min + (int) (round(($value - $min) / $step) * $step);
            $out[$resource] = max($min, min($max, $value));
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     | Pricing
     * ------------------------------------------------------------------- */

    /**
     * Discount applied to a resource, as a fraction, given how many units of
     * it were bought. The first unit is never discounted.
     */
    public function discountFor(float $units): float
    {
        if ($this->discount_per_unit <= 0) {
            return 0.0;
        }

        $percent = max(0.0, $units - 1) * (float) $this->discount_per_unit;
        $percent = min($percent, (float) $this->discount_max);

        return max(0.0, min(100.0, $percent)) / 100;
    }

    /** Whether this resource is part of the plan at all. */
    public function offers(string $resource): bool
    {
        $meta = self::RESOURCES[$resource] ?? null;

        return $meta !== null
            && ((int) $this->{$meta['price']} > 0 || (int) $this->{$meta['money']} > 0);
    }

    /**
     * Per-resource price breakdown, used both for the total and for showing
     * the customer where the money goes.
     *
     * @param  array<string, int>  $resources  already clamped
     * @return array<string, array{units: float, unit_price: int, discount: float, total: int}>
     */
    public function breakdown(array $resources, bool $inMoney = false): array
    {
        $lines = [];

        foreach (self::RESOURCES as $resource => $meta) {
            // Which resources are configurable is decided once, by either
            // price list. Otherwise a direct-only plan priced in money alone
            // would offer nothing, and a resource priced in credits but not in
            // money would silently drop out of the money total.
            if (!$this->offers($resource)) {
                continue;
            }

            $unitPrice = $inMoney
                ? $this->moneyUnitPrice($resource)
                : (int) $this->{$meta['price']};

            $units = ((int) ($resources[$resource] ?? 0)) / $meta['per'];

            if ($units <= 0) {
                continue;
            }

            $discount = $this->discountFor($units);

            $lines[$resource] = [
                'units' => $units,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'total' => (int) round($units * $unitPrice * (1 - $discount)),
            ];
        }

        return $lines;
    }

    /**
     * Total price for a configuration, in the 1000x integer scale.
     *
     * @param  array<string, int>  $resources  already clamped
     */
    public function priceFor(array $resources): int
    {
        return array_sum(array_column($this->breakdown($resources), 'total'));
    }

    /* ---------------------------------------------------------------------
     | Payment mode
     * ------------------------------------------------------------------- */

    public function allowsCredits(): bool
    {
        return in_array($this->payment_modes, ['credits', 'both'], true);
    }

    public function allowsDirect(): bool
    {
        return in_array($this->payment_modes, ['direct', 'both'], true)
            && ($this->hasMoneyPrices() || $this->credit_rate > 0);
    }

    /** Whether the admin entered a money price list of its own. */
    public function hasMoneyPrices(): bool
    {
        foreach (self::RESOURCES as $meta) {
            if ((int) $this->{$meta['money']} > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * What one unit of a resource costs in money.
     *
     * A plan from before the money price list has none, so its figure is
     * still derived from the credit price by `credit_rate`.
     */
    public function moneyUnitPrice(string $resource): int
    {
        $meta = self::RESOURCES[$resource] ?? null;

        if ($meta === null) {
            return 0;
        }

        if ($this->hasMoneyPrices()) {
            return (int) $this->{$meta['money']};
        }

        return (int) round(((int) $this->{$meta['price']}) * (float) $this->credit_rate);
    }

    /**
     * The same configuration expressed in money. Both figures use the 1000x
     * integer scale.
     *
     * @param  array<string, int>  $resources  already clamped
     */
    public function moneyPriceFor(array $resources): int
    {
        return array_sum(array_column($this->breakdown($resources, true), 'total'));
    }

    /* ---------------------------------------------------------------------
     | Placement
     * ------------------------------------------------------------------- */

    /**
     * Whether a configuration counts as a large order, which may only go on
     * nodes the admin flagged for it.
     *
     * @param  array<string, int>  $resources
     */
    public function isLargeOrder(array $resources): bool
    {
        return $this->large_order_memory !== null
            && ($resources['memory'] ?? 0) > (int) $this->large_order_memory;
    }

    /**
     * Nodes this configuration may be deployed on, best first.
     *
     * @param  array<string, int>  $resources
     * @return \Illuminate\Support\Collection<int, Node>
     */
    public function candidateNodes(array $resources)
    {
        $large = $this->isLargeOrder($resources);

        return $this->nodes()
            ->where('nodes.disabled', false)
            ->get()
            ->filter(fn (Node $node) => !$large || (bool) $node->pivot->allow_large)
            // Lower priority number is tried first; ties keep a stable order.
            ->sortBy([
                fn (Node $a, Node $b) => $a->pivot->priority <=> $b->pivot->priority,
                fn (Node $a, Node $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * First node in priority order that still has room, or null.
     *
     * @param  array<string, int>  $resources
     */
    public function pickNode(array $resources): ?Node
    {
        $client = app(GamePanelClient::class);

        foreach ($this->candidateNodes($resources) as $node) {
            try {
                if (!$client->checkNodeResources($node, $resources['memory'] ?? 0, $resources['disk'] ?? 0)) {
                    continue;
                }

                // A node with no free port cannot take the server either.
                if (!$client->getFreeAllocationId($node)) {
                    continue;
                }

                return $node;
            } catch (Throwable $e) {
                // An unreachable node must not block the ones after it.
                logger('CustomPlan: skipping node ' . $node->id . ' - ' . $e->getMessage());
            }
        }

        return null;
    }
}
