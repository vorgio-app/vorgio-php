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
            $checks[] = $this->check('token', $this->t('labels.token'), self::FAIL, $this->t('token.missing'));

            return $this->report(null, $checks);
        }
        $checks[] = $this->check('token', $this->t('labels.token'), self::OK, $this->t('token.set'));

        $connection = null;

        try {
            $connection = $this->container->make(VorgioClient::class)->connection()->retrieve();
            $checks[] = $this->check('reachable', $this->t('labels.reachable'), self::OK, $this->t('reachable.ok'));
        } catch (VorgioApiException $e) {
            $checks[] = $this->check(
                'reachable',
                $this->t('labels.reachable'),
                self::FAIL,
                $this->t('reachable.rejected', ['status' => $e->statusCode, 'message' => $e->getMessage()]),
            );

            return $this->report(null, $checks);
        } catch (Throwable $e) {
            $checks[] = $this->check('reachable', $this->t('labels.reachable'), self::FAIL, $this->t('reachable.unreachable', ['message' => $e->getMessage()]));

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
            return $this->check('mode', $this->t('labels.mode'), self::OK, $this->t('mode.live'));
        }

        if ($mode === 'test') {
            return $this->check('mode', $this->t('labels.mode'), self::WARN, $this->t('mode.test'));
        }

        return $this->check('mode', $this->t('labels.mode'), self::WARN, $this->t('mode.unknown'));
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
            return $this->check('abilities', $this->t('labels.abilities'), self::OK, $this->t('abilities.ok'));
        }

        return $this->check('abilities', $this->t('labels.abilities'), self::FAIL, $this->t('abilities.missing', ['abilities' => implode(', ', $missing)]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Check
     */
    private function webhookCheck(array $data): array
    {
        if ((string) $this->config->get('vorgio.webhook.secret', '') === '') {
            return $this->check('webhook', $this->t('labels.webhook'), self::FAIL, $this->t('webhook.no_secret'));
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
            return $this->check('webhook', $this->t('labels.webhook'), self::FAIL, $this->t('webhook.not_registered', ['url' => $expected]));
        }

        if (($match['active'] ?? false) !== true) {
            return $this->check('webhook', $this->t('labels.webhook'), self::FAIL, $this->t('webhook.inactive', ['url' => $expected]));
        }

        /** @var array<int, string> $requiredEvents */
        $requiredEvents = (array) $this->config->get('vorgio.required_webhook_events', []);
        $missingEvents = array_values(array_diff($requiredEvents, (array) ($match['events'] ?? [])));

        if ($missingEvents !== []) {
            return $this->check('webhook', $this->t('labels.webhook'), self::WARN, $this->t('webhook.missing_events', ['events' => implode(', ', $missingEvents)]));
        }

        return $this->check('webhook', $this->t('labels.webhook'), self::OK, $this->t('webhook.ok', ['url' => $expected]));
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
            return $this->check('migrations', $this->t('labels.migrations'), self::OK, $this->t('migrations.ok'));
        }

        return $this->check('migrations', $this->t('labels.migrations'), self::FAIL, $this->t('migrations.missing', ['tables' => implode(', ', $missing)]));
    }

    /**
     * @return Check
     */
    private function webhookDeliveryCheck(): array
    {
        try {
            $response = $this->container->make(VorgioClient::class)->connection()->sendTestWebhook();
        } catch (Throwable $e) {
            return $this->check('webhook_delivery', $this->t('labels.webhook_delivery'), self::FAIL, $this->t('webhook_delivery.request_failed', ['message' => $e->getMessage()]));
        }

        /** @var array<int, array<string, mixed>> $results */
        $results = (array) ($response['data']['results'] ?? []);

        if ($results === []) {
            return $this->check('webhook_delivery', $this->t('labels.webhook_delivery'), self::FAIL, $this->t('webhook_delivery.none_received'));
        }

        foreach ($results as $result) {
            if (($result['ok'] ?? false) !== true) {
                $url = (string) ($result['url'] ?? '?');
                $status = $result['status'] ?? null;

                if ($status !== null) {
                    $detail = $this->t('webhook_delivery.rejected_status', ['url' => $url, 'status' => $status]);
                } elseif (isset($result['error'])) {
                    $detail = $this->t('webhook_delivery.rejected_error', ['url' => $url, 'error' => $result['error']]);
                } else {
                    $detail = $this->t('webhook_delivery.rejected_unknown', ['url' => $url]);
                }

                return $this->check('webhook_delivery', $this->t('labels.webhook_delivery'), self::FAIL, $detail);
            }
        }

        return $this->check('webhook_delivery', $this->t('labels.webhook_delivery'), self::OK, $this->t('webhook_delivery.ok'));
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

    /**
     * Resolve a check label/detail in the app's current locale. Strings live
     * under the publishable `vorgio::doctor.*` namespace so consuming apps can
     * override them or add further languages without touching this package.
     *
     * @param  array<string, mixed>  $replace
     */
    private function t(string $key, array $replace = []): string
    {
        return (string) trans('vorgio::doctor.'.$key, $replace);
    }
}
