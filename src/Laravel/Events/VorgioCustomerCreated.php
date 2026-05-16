<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Events;

use Vorgio\Laravel\Models\VorgioBillable;

/**
 * Fired by the `Billable` trait when a Vorgio client is first provisioned
 * for a local billable model (either via `createAsVorgioCustomer()` or as
 * a side effect of the first `subscribe()` call).
 */
final class VorgioCustomerCreated
{
    public function __construct(public readonly VorgioBillable $billable)
    {
    }
}
