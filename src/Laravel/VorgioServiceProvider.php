<?php

declare(strict_types=1);

namespace Vorgio\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vorgio\Exception\VorgioException;
use Vorgio\VorgioClient;

/**
 * Auto-discovered when running inside Laravel.
 *
 * Outside Laravel — e.g. in a vanilla WordPress plugin — this class is
 * loaded but never invoked, so the package stays usable wherever Composer
 * works.
 */
class VorgioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/vorgio.php', 'vorgio');

        $this->app->singleton(VorgioClient::class, function (Application $app): VorgioClient {
            $token = (string) config('vorgio.token', '');

            if ($token === '') {
                throw new VorgioException(
                    'Vorgio token is not configured. Set VORGIO_TOKEN in your .env file.',
                );
            }

            return new VorgioClient(
                token: $token,
                baseUrl: (string) config('vorgio.base_url', 'https://app.vorgio.example'),
                timeout: (float) config('vorgio.timeout', VorgioClient::DEFAULT_TIMEOUT),
            );
        });

        $this->app->alias(VorgioClient::class, 'vorgio');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/vorgio.php' => $this->app->configPath('vorgio.php'),
            ], 'vorgio-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [VorgioClient::class, 'vorgio'];
    }
}
