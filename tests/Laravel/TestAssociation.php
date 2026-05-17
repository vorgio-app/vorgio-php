<?php

declare(strict_types=1);

namespace Vorgio\Tests\Laravel;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Vorgio\Laravel\Billable;

/**
 * Throwaway billable model used by the Cashier-style tests.
 *
 * Plays the same role MVGV's `Association` will: an in-app domain entity
 * that gets billed, keyed by UUID. Exercises the polymorphic billable_id
 * path against the string column type introduced in v0.2.1.
 */
class TestAssociation extends Model
{
    use Billable;
    use HasUuids;

    protected $table = 'test_associations';

    protected $guarded = [];
}
