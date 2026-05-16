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

        Schema::create('test_associations', function (Blueprint $table) {
            $table->id();
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
