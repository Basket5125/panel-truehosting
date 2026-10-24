<?php

namespace App\Models;

use Hidehalo\Nanoid\Client;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configured order that is waiting on money: either the first period of a
 * new server, or one renewal of an existing one.
 */
class CustomOrder extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'resources' => 'array',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONED = 'provisioned';

    public const STATUS_RENEWED = 'renewed';

    public const STATUS_FAILED = 'failed';

    public const KIND_FIRST = 'first_period';

    public const KIND_RENEWAL = 'renewal';

    public static function boot()
    {
        parent::boot();

        static::creating(function (CustomOrder $order) {
            $order->{$order->getKeyName()} = (new Client())->generateId(21);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CustomPlan::class, 'custom_plan_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
