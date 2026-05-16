<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Local mirror of one Vorgio invoice belonging to one of our billables.
 *
 * Populated by the webhook controller on `invoice.sent`, `invoice.paid`,
 * `invoice.cancelled` events. Read-only from the consumer's POV — Vorgio
 * is the source of truth.
 *
 * @property string $id
 * @property string $vorgio_billable_id
 * @property string $vorgio_invoice_id
 * @property string|null $parent_invoice_id
 * @property string $status
 * @property int $total_cents
 * @property string $currency
 * @property Carbon|null $sent_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 */
class Invoice extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'total_cents' => 'integer',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('vorgio.table_prefix', 'vorgio_').'invoices';
    }

    public function billable(): BelongsTo
    {
        return $this->belongsTo(VorgioBillable::class, 'vorgio_billable_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DRAFT], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
