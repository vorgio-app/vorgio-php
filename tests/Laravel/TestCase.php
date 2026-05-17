<?php

declare(strict_types=1);

namespace Vorgio\Tests\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Vorgio\Laravel\VorgioServiceProvider;

/**
 * Base test case for the Cashier-style Laravel layer.
 *
 * Boots a Laravel application with the package's service provider, runs
 * the package migrations + a throwaway `test_associations` table that
 * mimics the kind of consumer billable a real integrator would use, and
 * binds the SDK to a Guzzle MockHandler so HTTP can be asserted in-process.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UUID primary key mirrors MVGV's real Association model (HasUuids).
        // The previous bigint id hid a v0.2.0 regression where the polymorphic
        // billable_id column was unsignedBigInteger and silently truncated
        // UUIDs to 0. Keep the fixture UUID-keyed so the bigint→string
        // schema change stays under test.
        Schema::create('test_associations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
    }

    protected function getPackageProviders($app): array
    {
        return [VorgioServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Foreign-key cascades on SQLite need this pragma.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        $app['config']->set('vorgio.token', 'act_test');
        $app['config']->set('vorgio.base_url', 'https://vorgio.test');
        $app['config']->set('vorgio.retry.enabled', false);
        $app['config']->set('vorgio.webhook.secret', 'wsec_test');
    }
}
