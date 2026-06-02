<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vorgio\Laravel\ConnectionDoctor;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function connectionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'data' => [
            'team' => ['slug' => 'michael-becker', 'name' => 'Michael Becker'],
            'mode' => 'live',
            'token' => ['name' => 'mvgv', 'abilities' => [
                'clients:write', 'invoices:write', 'invoices:send', 'invoices:read', 'checkouts:write', 'webhooks:manage',
            ]],
            'billing' => ['has_iban' => true],
            'webhooks' => [[
                'url' => 'http://localhost/vorgio/webhook',
                'events' => ['invoice.sent', 'invoice.paid'],
                'active' => true,
            ]],
        ],
    ], $overrides);
}

/**
 * @return array<string, array{key: string, label: string, status: string, detail: string}>
 */
function checksByKey(array $report): array
{
    return collect($report['checks'])->keyBy('key')->all();
}

it('reports a healthy connection', function (): void {
    vorgioBindMockClient([jsonResponse(200, connectionPayload())]);

    $report = app(ConnectionDoctor::class)->run();

    $checks = checksByKey($report);

    expect($report['ok'])->toBeTrue()
        ->and($checks['reachable']['status'])->toBe(ConnectionDoctor::OK)
        ->and($checks['mode']['status'])->toBe(ConnectionDoctor::OK)
        ->and($checks['abilities']['status'])->toBe(ConnectionDoctor::OK)
        ->and($checks['webhook']['status'])->toBe(ConnectionDoctor::OK)
        ->and($checks['migrations']['status'])->toBe(ConnectionDoctor::OK);
});

it('fails fast when no token is configured', function (): void {
    config()->set('vorgio.token', '');

    $report = app(ConnectionDoctor::class)->run();

    expect($report['ok'])->toBeFalse()
        ->and($report['connection'])->toBeNull()
        ->and(checksByKey($report)['token']['status'])->toBe(ConnectionDoctor::FAIL);
});

it('warns (but does not fail) when the token is in test mode', function (): void {
    vorgioBindMockClient([jsonResponse(200, connectionPayload(['data' => ['mode' => 'test']]))]);

    $report = app(ConnectionDoctor::class)->run();

    expect($report['ok'])->toBeTrue()
        ->and(checksByKey($report)['mode']['status'])->toBe(ConnectionDoctor::WARN);
});

it('fails when a required ability is missing', function (): void {
    $payload = connectionPayload();
    $payload['data']['token']['abilities'] = ['invoices:read'];

    vorgioBindMockClient([jsonResponse(200, $payload)]);

    $report = app(ConnectionDoctor::class)->run();

    $abilities = checksByKey($report)['abilities'];

    expect($report['ok'])->toBeFalse()
        ->and($abilities['status'])->toBe(ConnectionDoctor::FAIL)
        ->and($abilities['detail'])->toContain('checkouts:write');
});

it('fails when no matching webhook is registered', function (): void {
    $payload = connectionPayload();
    $payload['data']['webhooks'] = [];

    vorgioBindMockClient([jsonResponse(200, $payload)]);

    $report = app(ConnectionDoctor::class)->run();

    expect($report['ok'])->toBeFalse()
        ->and(checksByKey($report)['webhook']['status'])->toBe(ConnectionDoctor::FAIL);
});

it('fails when the matching webhook is inactive', function (): void {
    vorgioBindMockClient([jsonResponse(200, connectionPayload([
        'data' => ['webhooks' => [['active' => false]]],
    ]))]);

    $report = app(ConnectionDoctor::class)->run();

    expect(checksByKey($report)['webhook']['status'])->toBe(ConnectionDoctor::FAIL);
});

it('fails reachability when Vorgio rejects the token', function (): void {
    vorgioBindMockClient([problemResponse(401, 'Unauthenticated')]);

    $report = app(ConnectionDoctor::class)->run();

    expect($report['ok'])->toBeFalse()
        ->and(checksByKey($report)['reachable']['status'])->toBe(ConnectionDoctor::FAIL);
});

it('includes a passing webhook-delivery check when requested', function (): void {
    vorgioBindMockClient([
        jsonResponse(200, connectionPayload()),
        jsonResponse(200, ['data' => ['results' => [
            ['url' => 'http://localhost/vorgio/webhook', 'ok' => true, 'status' => 200, 'error' => null],
        ]]]),
    ]);

    $report = app(ConnectionDoctor::class)->run(includeWebhookTest: true);

    expect($report['ok'])->toBeTrue()
        ->and(checksByKey($report)['webhook_delivery']['status'])->toBe(ConnectionDoctor::OK);
});

it('fails the webhook-delivery check when the endpoint rejects the event', function (): void {
    vorgioBindMockClient([
        jsonResponse(200, connectionPayload()),
        jsonResponse(200, ['data' => ['results' => [
            ['url' => 'http://localhost/vorgio/webhook', 'ok' => false, 'status' => 400, 'error' => null],
        ]]]),
    ]);

    $report = app(ConnectionDoctor::class)->run(includeWebhookTest: true);

    expect($report['ok'])->toBeFalse()
        ->and(checksByKey($report)['webhook_delivery']['status'])->toBe(ConnectionDoctor::FAIL);
});

it('runs the vorgio:doctor command and exits 0 when healthy', function (): void {
    vorgioBindMockClient([jsonResponse(200, connectionPayload())]);

    expect(Artisan::call('vorgio:doctor'))->toBe(0);
});

it('exits non-zero from vorgio:doctor when a check fails', function (): void {
    vorgioBindMockClient([problemResponse(401, 'Unauthenticated')]);

    expect(Artisan::call('vorgio:doctor'))->toBe(1);
});

it('registers the publishable vorgio:: translation namespace', function (): void {
    // An unregistered namespace would echo the key back verbatim; a real
    // translated value proves loadTranslationsFrom('vorgio') took effect.
    expect(trans('vorgio::doctor.labels.token'))->toBe('API token');
});

it('localizes check labels and details to the app locale', function (): void {
    app()->setLocale('de');

    $payload = connectionPayload();
    $payload['data']['token']['abilities'] = ['invoices:read'];

    vorgioBindMockClient([jsonResponse(200, $payload)]);

    $checks = checksByKey(app(ConnectionDoctor::class)->run());

    expect($checks['token']['label'])->toBe('API-Token')
        ->and($checks['mode']['detail'])->toBe('Token ist im LIVE-Modus.')
        // Interpolated placeholder values stay verbatim inside the translated string.
        ->and($checks['abilities']['detail'])->toContain('Fehlende Berechtigungen:')
        ->and($checks['abilities']['detail'])->toContain('checkouts:write');
});

it('falls back per-key to other locales (fr/es resolve)', function (): void {
    app()->setLocale('fr');
    expect(trans('vorgio::doctor.labels.mode'))->toBe('Mode');

    app()->setLocale('es');
    expect(trans('vorgio::doctor.labels.mode'))->toBe('Modo');
});
