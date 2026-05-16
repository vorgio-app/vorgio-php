<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Local read view of a Vorgio recurring template.
 *
 * Created by the trait's {@see \Vorgio\Laravel\Billable::subscribe()} call
 * and updated by webhook events. The authoritative state still lives in
 * Vorgio — refer to `vorgio_invoice_id` to fetch it.
 *
 * @property string $id
 * @property string $vorgio_billable_id
 * @property string $vorgio_invoice_id
 * @property string $every
 * @property Carbon|null $next_invoice_at
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $stopped_at
 * @property Carbon|null $cancelled_at
 */
class Subscription extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'next_invoice_at' => 'datetime',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('vorgio.table_prefix', 'vorgio_').'subscriptions';
    }

    public function billable(): BelongsTo
    {
        return $this->belongsTo(VorgioBillable::class, 'vorgio_billable_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
