<?php

declare(strict_types=1);

namespace Vorgio\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Vorgio\Exception\VorgioApiException;
use Vorgio\VorgioClient;

/**
 * Diagnoses whether the host app is correctly connected to Vorgio.
 *
 * Produces a structured report — a list of pass/warn/fail checks plus the
 * raw `connection` payload — shared by the `vorgio:doctor` command and any
 * GUI (e.g. an operator settings page) that wants to render connection
 * health. The expensive, side-effecting webhook round-trip is opt-in via the
 * `$includeWebhookTest` flag so the cheap introspection can run on every page
 * load.
 *
 * @phpstan-type Check array{key: string, label: string, status: string, detail: string}
 */
final class ConnectionDoctor
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
    ) {
    }

    /**
     * @return array{ok: bool, connection: array<string, mixed>|null, checks: array<int, Check>}
     */
    public function run(bool $includeWebhookTest = false): array
    {
        $checks = [];

        $token = (string) $this->config->get('vorgio.token', '');
        if ($token === '') {
            $checks[] = $this->check('token', 'API token', self::FAIL, 'VORGIO_TOKEN is not set.');

            return $this->report(null, $checks);
        }
        $checks[] = $this->check('token', 'API token', self::OK, 'VORGIO_TOKEN is set.');

        $connection = null;

        try {
            $connection = $this->container->make(VorgioClient::class)->connection()->retrieve();
            $checks[] = $this->check('reachable', 'Reachable & authenticated', self::OK, 'Vorgio accepted the token.');
        } catch (VorgioApiException $e) {
            $checks[] = $this->check(
                'reachable',
                'Reachable & authenticated',
                self::FAIL,
                'Vorgio rejected the request (HTTP '.$e->statusCode.'): '.$e->getMessage(),
            );

            return $this->report(null, $checks);
        } catch (Throwable $e) {
            $checks[] = $this->check('reachable', 'Reachable & authenticated', self::FAIL, 'Could not reach Vorgio: '.$e->getMessage());

            return $this->report(null, $checks);
        }

        $data = is_array($connection['data'] ?? null) ? $connection['data'] : $connection;

        $checks[] = $this->modeCheck($data);
        $checks[] = $this->abilitiesCheck($data);
        $checks[] = $this->webhookCheck($data);
        $checks[] = $this->mirrorTablesCheck();

        if ($includeWebhookTest) {
            $checks[] = $this->webhookDeliveryCheck();
        }

        return $this->report($data, $checks);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Check
     */
    private function modeCheck(array $data): array
    {
        $mode = (string) ($data['mode'] ?? '');

        if ($mode === 'live') {
            return $this->check('mode', 'Mode', self::OK, 'Token is in LIVE mode.');
        }

        if ($mode === 'test') {
            return $this->check('mode', 'Mode', self::WARN, 'Token is in TEST mode — test invoices are not emailed and do not bill. Use a LIVE token for production.');
        }

        return $this->check('mode', 'Mode', self::WARN, 'Unknown mode.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Check
     */
    private function abilitiesCheck(array $data): array
    {
        /** @var array<int, string> $required */
        $required = (array) $this->config->get('vorgio.required_abilities', []);
        /** @var array<int, string> $granted */
        $granted = (array) ($data['token']['abilities'] ?? []);

        $missing = array_values(array_diff($required, $granted));

        if ($missing === []) {
            return $this->check('abilities', 'Token abilities', self::OK, 'All required abilities are granted.');
        }

        return $this->check('abilities', 'Token abilities', self::FAIL, 'Missing abilities: '.implode(', ', $missing).'. Re-issue the token with the matching preset.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Check
     */
    private function webhookCheck(array $data): array
    {
        if ((string) $this->config->get('vorgio.webhook.secret', '') === '') {
            return $this->check('webhook', 'Webhook', self::FAIL, 'VORGIO_WEBHOOK_SECRET is not set — this app cannot receive webhooks.');
        }

        $expected = $this->expectedWebhookUrl();
        /** @var array<int, array<string, mixed>> $webhooks */
        $webhooks = (array) ($data['webhooks'] ?? []);

        $match = null;
        foreach ($webhooks as $webhook) {
            if ($this->normalizeUrl((string) ($webhook['url'] ?? '')) === $this->normalizeUrl($expected)) {
                $match = $webhook;
                break;
            }
        }

        if ($match === null) {
            return $this->check('webhook', 'Webhook', self::FAIL, 'No webhook registered for '.$expected.'. Add one in your Vorgio team settings.');
        }

        if (($match['active'] ?? false) !== true) {
            return $this->check('webhook', 'Webhook', self::FAIL, 'The webhook for '.$expected.' is inactive (likely auto-disabled after repeated delivery failures). Re-enable it.');
        }

        /** @var array<int, string> $requiredEvents */
        $requiredEvents = (array) $this->config->get('vorgio.required_webhook_events', []);
        $missingEvents = array_values(array_diff($requiredEvents, (array) ($match['events'] ?? [])));

        if ($missingEvents !== []) {
            return $this->check('webhook', 'Webhook', self::WARN, 'Webhook is registered but not subscribed to: '.implode(', ', $missingEvents).'.');
        }

        return $this->check('webhook', 'Webhook', self::OK, 'Active webhook registered for '.$expected.' with the required events.');
    }

    /**
     * @return Check
     */
    private function mirrorTablesCheck(): array
    {
        $prefix = (string) $this->config->get('vorgio.table_prefix', 'vorgio_');
        $tables = ['billables', 'subscriptions', 'invoices', 'operations'];

        $missing = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($prefix.$table)) {
                $missing[] = $prefix.$table;
            }
        }

        if ($missing === []) {
            return $this->check('migrations', 'Mirror tables', self::OK, 'All Vorgio mirror tables exist.');
        }

        return $this->check('migrations', 'Mirror tables', self::FAIL, 'Missing tables: '.implode(', ', $missing).'. Run `php artisan migrate`.');
    }

    /**
     * @return Check
     */
    private function webhookDeliveryCheck(): array
    {
        try {
            $response = $this->container->make(VorgioClient::class)->connection()->sendTestWebhook();
        } catch (Throwable $e) {
            return $this->check('webhook_delivery', 'Webhook delivery', self::FAIL, 'Test webhook request failed: '.$e->getMessage());
        }

        /** @var array<int, array<string, mixed>> $results */
        $results = (array) ($response['data']['results'] ?? []);

        if ($results === []) {
            return $this->check('webhook_delivery', 'Webhook delivery', self::FAIL, 'No active webhook received the test event.');
        }

        foreach ($results as $result) {
            if (($result['ok'] ?? false) !== true) {
                $detail = 'Endpoint '.($result['url'] ?? '?').' did not accept the test event';
                $status = $result['status'] ?? null;
                $detail .= $status !== null ? ' (HTTP '.$status.').' : (isset($result['error']) ? ': '.$result['error'] : '.');

                return $this->check('webhook_delivery', 'Webhook delivery', self::FAIL, $detail.' Check the URL and that VORGIO_WEBHOOK_SECRET matches.');
            }
        }

        return $this->check('webhook_delivery', 'Webhook delivery', self::OK, 'Your endpoint received and verified a signed test event.');
    }

    private function expectedWebhookUrl(): string
    {
        $route = (string) $this->config->get('vorgio.webhook.route', '/vorgio/webhook');

        return url($route);
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(strtolower($url), '/');
    }

    /**
     * @param  array<string, mixed>|null  $connection
     * @param  array<int, Check>  $checks
     * @return array{ok: bool, connection: array<string, mixed>|null, checks: array<int, Check>}
     */
    private function report(?array $connection, array $checks): array
    {
        $ok = ! in_array(self::FAIL, array_column($checks, 'status'), true);

        return ['ok' => $ok, 'connection' => $connection, 'checks' => $checks];
    }

    /**
     * @return Check
     */
    private function check(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
