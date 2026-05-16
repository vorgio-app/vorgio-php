<?php

declare(strict_types=1);

namespace Vorgio\Tests\Laravel;

use Illuminate\Database\Eloquent\Model;
use Vorgio\Laravel\Billable;

/**
 * Throwaway billable model used by the Cashier-style tests.
 *
 * Plays the same role MVGV's `Association` will: an in-app domain entity
 * that gets billed. The test only cares that `use Billable` works on any
 * model, regardless of its key shape.
 */
class TestAssociation extends Model
{
    use Billable;

    protected $table = 'test_associations';

    protected $guarded = [];
}
