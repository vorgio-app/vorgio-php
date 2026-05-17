<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic bridge between a consumer's billable model (e.g.
 * `App\Models\Association`) and its Vorgio client/subscription state.
 *
 * One row per (billable_type, billable_id). The trait
 * {@see \Vorgio\Laravel\Billable} populates this on the first
 * `subscribe()` / `createAsVorgioCustomer()` call and points the consumer
 * back here for relationship access.
 *
 * @property string $id
 * @property string $billable_type
 * @property string $billable_id
 * @property string $vorgio_client_id
 */
class VorgioBillable extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('vorgio.table_prefix', 'vorgio_').'billables';
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'vorgio_billable_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'vorgio_billable_id');
    }
}
