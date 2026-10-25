<?php

namespace App\Models;

use Carbon\Carbon;
use App\Contracts\GamePanelClient;
use App\Enums\BillingPriority;
use App\Settings\PterodactylSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Hidehalo\Nanoid\Client;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\Response;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Exception;

/**
 * Class Server
 */
class Server extends Model
{
    use HasFactory;
    use LogsActivity;

    private GamePanelClient $pterodactyl;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string[]
     */
    protected static $ignoreChangedAttributes = ['pterodactyl_id', 'identifier', 'updated_at'];

    /**
     * @var string[]
     */
    protected static $logAttributes = ['name', 'description'];

    /**
     * @var string[]
     */
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING_RECONCILIATION = 'pending_reconciliation';

    protected $fillable = [
        "name",
        "description",
        "suspended",
        "suspension_warning_sent_at",
        "identifier",
        "billing_priority",
        "product_id",
        "node_id",
        "trial_ends_at",
        "trial_extensions",
        "pterodactyl_id",
        "user_id",
        "last_billed",
        "canceled",
        "status"
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'suspended' => 'datetime',
        'last_billed' => 'datetime',
        'canceled' => 'datetime',
        'billing_priority' => BillingPriority::class
    ];

    public function __construct()
    {
        parent::__construct();

        $ptero_settings = new PterodactylSettings();
        $this->pterodactyl = app(GamePanelClient::class);
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function (Server $server) {
            $client = new Client();

            $server->{$server->getKeyName()} = $client->generateId($size = 21);
        });

        static::deleting(function (Server $server) {
            if (is_null($server->pterodactyl_id)) {
                return;
            }

            $panelServerId = $server->pterodactyl_id;

            // Deleting is one of the calls the two panels disagree about, so a
            // refusal on the configured one is retried on the other.
            $attempt = (new \App\Services\GamePanelFallback($server->pterodactyl))->attempt(
                fn ($client) => $client->deleteServer($panelServerId),
                'delete:' . $panelServerId
            );

            $response = $attempt['response'];

            // A server that is already gone from the panel is not an error
            // here; every other failure is. The status is checked rather than
            // the body because each panel reports errors in its own shape.
            if ($response === null) {
                throw new Exception('Could not reach the game panel to delete this server.');
            }

            if ($response->failed() && $response->status() !== 404) {
                throw new Exception(
                    'Could not delete the server on ' . $attempt['client']->panelName()
                    . ' (HTTP ' . $response->status() . ')'
                );
            }
        });
    }

    /** A free trial server, which expires instead of being billed. */
    public function isTrial(): bool
    {
        return $this->trial_ends_at !== null;
    }

    /** Whether a trial has run past its time. */
    public function trialExpired(): bool
    {
        return $this->isTrial() && $this->trial_ends_at->isPast();
    }

    /** Billed by a payment each period rather than from credits. */
    public function isDirectBilled(): bool
    {
        return $this->payment_mode === 'direct';
    }

    /**
     * Whether a directly billed server is past its paid period, including the
     * plan's grace days.
     */
    public function isOverdue(): bool
    {
        if (!$this->isDirectBilled() || $this->paid_until === null) {
            return false;
        }

        $grace = (int) ($this->product?->customPlan?->grace_days ?? 0);

        return \Carbon\Carbon::parse($this->paid_until)->addDays($grace)->isPast();
    }

    /**
     * @return bool
     */
    public function isSuspended()
    {
        return !is_null($this->suspended);
    }

    /**
     * @return PromiseInterface|Response
     */
    public function getPterodactylServer()
    {
        return $this->pterodactyl->getServerById($this->pterodactyl_id);
    }

    /**
     * @throws Exception
     */
    public function suspend()
    {
        $response = $this->pterodactyl->suspendServer($this);

        if ($response->successful()) {
            $this->update([
                'suspended' => now(),
            ]);
        }

        return $this;
    }

    /**
     * @throws Exception
     */
    public function unSuspend()
    {
        $response = $this->pterodactyl->unSuspendServer($this);

        if ($response->successful()) {
            $this->update([
                'suspended' => null,
                'last_billed' => Carbon::now()->toDateTimeString(),
                'suspension_warning_sent_at' => null,
            ]);
        }


        return $this;
    }

    /**
     * @return BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    /**
     * How the server's power state should be shown.
     *
     * Kept here rather than in the view because the servers list renders it
     * once on the server and then keeps it up to date over JSON; both have to
     * agree on the wording and the colour.
     *
     * @return array{key: string, class: string, label: string}
     */
    public function powerStateInfo(): array
    {
        $state = strtolower((string) ($this->state ?? ''));

        // Billing state wins over whatever the game panel reports.
        return match (true) {
            (bool) $this->suspended => ['key' => 'suspended', 'class' => 'is-suspended', 'label' => __('Suspended')],
            (bool) $this->canceled => ['key' => 'canceled', 'class' => 'is-pending', 'label' => __('Canceled')],
            $state === 'running' => ['key' => 'running', 'class' => 'is-online', 'label' => __('Running')],
            in_array($state, ['starting', 'installing', 'restarting'], true)
                => ['key' => 'starting', 'class' => 'is-pending', 'label' => __('Starting')],
            $state === 'stopping' => ['key' => 'stopping', 'class' => 'is-pending', 'label' => __('Stopping')],
            in_array($state, ['offline', 'stopped'], true) => ['key' => 'offline', 'class' => '', 'label' => __('Offline')],
            default => ['key' => 'unknown', 'class' => 'is-online', 'label' => __('Active')],
        };
    }

    /** Whether the customer may send this server a power signal. */
    public function canBePowered(): bool
    {
        return !$this->suspended
            && !$this->canceled
            && $this->status === self::STATUS_ACTIVE
            && !empty($this->identifier);
    }

    /**
     * The node this server was deployed on. Null on rows created before the
     * column existed, until `cp:servers:syncnodes` fills them in.
     *
     * @return BelongsTo
     */
    public function node()
    {
        return $this->belongsTo(\App\Models\Pterodactyl\Node::class, 'node_id', 'id');
    }

    /**
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function getEffectiveBillingPriorityAttribute()
    {
        return $this->billing_priority ?? $this->product->default_billing_priority;
    }

    public function scopeByBillingPriority($query)
    {
        return $query->orderByRaw('COALESCE(servers.billing_priority, (
                SELECT default_billing_priority
                FROM products
                WHERE products.id = servers.product_id
            ))')
            ->orderBy('created_at', 'asc');
    }
}
