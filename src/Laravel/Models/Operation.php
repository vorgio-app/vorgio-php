<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One row per in-flight operation against the Vorgio API.
 *
 * The Cashier-style trait writes a `pending` row before the SDK call so
 * that queue retries — even those crossing midnight — replay the same
 * `operation_id` and therefore the same `Idempotency-Key`. On success the
 * row is marked completed (kept for audit); on hard failure marked failed.
 *
 * Consumers normally never touch this table directly. It's exposed as a
 * model so power users can introspect stuck operations from a console.
 *
 * @property string $id
 * @property string $billable_type
 * @property int $billable_id
 * @property string $purpose
 * @property string $operation_id
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $completed_at
 */
class Operation extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const PURPOSE_SUBSCRIBE = 'subscribe';
    public const PURPOSE_CHANGE_CYCLE = 'change_cycle';
    public const PURPOSE_CANCEL = 'cancel';
    public const PURPOSE_CREATE_CUSTOMER = 'create_customer';

    protected $guarded = [];

    protected $casts = [
        'attempts' => 'integer',
        'last_attempted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('vorgio.table_prefix', 'vorgio_').'operations';
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
