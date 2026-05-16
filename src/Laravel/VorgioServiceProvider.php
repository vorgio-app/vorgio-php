<?php

declare(strict_types=1);

namespace Vorgio\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Vorgio\Exception\VorgioException;
use Vorgio\Laravel\Http\WebhookController;
use Vorgio\Support\RetryPolicy;
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
            $config = $app->make(ConfigRepository::class);
            $token = (string) $config->get('vorgio.token', '');

            if ($token === '') {
                throw new VorgioException(
                    'Vorgio token is not configured. Set VORGIO_TOKEN in your .env file.',
                );
            }

            return new VorgioClient(
                token: $token,
                baseUrl: (string) $config->get('vorgio.base_url', 'https://vorgio.app'),
                timeout: (float) $config->get('vorgio.timeout', VorgioClient::DEFAULT_TIMEOUT),
                retry: new RetryPolicy(
                    enabled: (bool) $config->get('vorgio.retry.enabled', true),
                ),
            );
        });

        $this->app->alias(VorgioClient::class, 'vorgio');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->registerWebhookRoute();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/vorgio.php' => $this->app->configPath('vorgio.php'),
            ], 'vorgio-config');

            $this->publishes([
                __DIR__.'/database/migrations' => $this->app->databasePath('migrations'),
            ], 'vorgio-migrations');
        }
    }

    private function registerWebhookRoute(): void
    {
        $secret = (string) config('vorgio.webhook.secret', '');
        if ($secret === '') {
            // No webhook secret set → don't register a route. Consumers
            // can still POST manually if they wire it up themselves.
            return;
        }

        $route = (string) config('vorgio.webhook.route', '/vorgio/webhook');
        $middleware = (array) config('vorgio.webhook.middleware', ['api']);

        Route::middleware($middleware)
            ->post($route, WebhookController::class)
            ->name('vorgio.webhook');
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [VorgioClient::class, 'vorgio'];
    }
}
