<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Vorgio\Support\OperationDerivation;
use Vorgio\Util\Uuid;

it('starts a subscription via POST /v1/checkouts with `every`', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, [
            'data' => [
                'client_id' => 'cli_1',
                'invoice' => ['id' => 'inv_1', 'every' => 'monthly', 'next_invoice_at' => '2026-06-15'],
                'mail_event_id' => 'me_1',
            ],
        ]),
    ]);

    $opId = Uuid::v7();
    $response = $client->subscriptions()->start(
        [
            'client' => ['name' => 'Verein', 'email' => 'kasse@verein.test'],
            'every' => 'monthly',
            'invoice' => [
                'subject' => 'Mitgliedsbeitrag',
                'tax_rate' => 19,
                'positions' => [['mode' => 'fixed', 'amount_cents' => 9900]],
            ],
            'metadata' => ['association_id' => 42],
        ],
        operationId: $opId,
    );

    /** @var Request $req */
    $req = $history[0]['request'];
    $body = json_decode((string) $req->getBody(), true);

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/checkouts')
        ->and($body['every'])->toBe('monthly')
        ->and($req->getHeaderLine('Idempotency-Key'))
        ->toBe((new OperationDerivation($opId))->idempotencyKey('subscription.start'))
        ->and($response['data']['invoice']['id'])->toBe('inv_1');
});

it('changes cycle via POST /v1/invoices/{id}/change-cycle', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'inv_1', 'every' => 'yearly']]),
    ]);

    $opId = Uuid::v7();
    $response = $client->subscriptions()->changeCycle('inv_1', 'yearly', operationId: $opId);

    /** @var Request $req */
    $req = $history[0]['request'];
    $body = json_decode((string) $req->getBody(), true);

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1/change-cycle')
        ->and($body)->toBe(['every' => 'yearly'])
        ->and($req->getHeaderLine('Idempotency-Key'))
        ->toBe((new OperationDerivation($opId))->idempotencyKey('subscription.change-cycle'))
        ->and($response['data']['every'])->toBe('yearly');
});

it('forwards next_invoice_at when provided to changeCycle', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'inv_1']]),
    ]);

    $client->subscriptions()->changeCycle('inv_1', 'monthly', nextInvoiceAt: '2026-06-01');

    /** @var Request $req */
    $req = $history[0]['request'];
    $body = json_decode((string) $req->getBody(), true);

    expect($body)->toBe(['every' => 'monthly', 'next_invoice_at' => '2026-06-01']);
});

it('stops a subscription via POST /v1/invoices/{id}/stop-recurring', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'inv_1', 'recurring_stopped_at' => '2026-05-16T10:00:00Z']]),
    ]);

    $opId = Uuid::v7();
    $response = $client->subscriptions()->stop('inv_1', operationId: $opId);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1/stop-recurring')
        ->and($req->getHeaderLine('Idempotency-Key'))
        ->toBe((new OperationDerivation($opId))->idempotencyKey('subscription.stop'))
        ->and($response['data']['recurring_stopped_at'])->toBe('2026-05-16T10:00:00Z');
});

it('replays the same Idempotency-Key + body when stop() is called twice with the same operationId', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'inv_1']]),
        jsonResponse(200, ['data' => ['id' => 'inv_1']]),
    ]);

    $opId = Uuid::v7();
    $client->subscriptions()->stop('inv_1', operationId: $opId);
    $client->subscriptions()->stop('inv_1', operationId: $opId);

    /** @var Request $first */
    $first = $history[0]['request'];
    /** @var Request $second */
    $second = $history[1]['request'];

    expect($first->getHeaderLine('Idempotency-Key'))->toBe($second->getHeaderLine('Idempotency-Key'))
        ->and((string) $first->getBody())->toBe((string) $second->getBody());
});

it('start / changeCycle / stop under one operationId produce distinct per-purpose keys', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => ['invoice' => ['id' => 'inv_1']]]),
        jsonResponse(200, ['data' => ['id' => 'inv_1']]),
        jsonResponse(200, ['data' => ['id' => 'inv_1']]),
    ]);

    $opId = Uuid::v7();
    $d = new OperationDerivation($opId);

    $client->subscriptions()->start(['client' => [], 'invoice' => []], operationId: $opId);
    $client->subscriptions()->changeCycle('inv_1', 'yearly', operationId: $opId);
    $client->subscriptions()->stop('inv_1', operationId: $opId);

    expect($history[0]['request']->getHeaderLine('Idempotency-Key'))
        ->toBe($d->idempotencyKey('subscription.start'))
        ->and($history[1]['request']->getHeaderLine('Idempotency-Key'))
        ->toBe($d->idempotencyKey('subscription.change-cycle'))
        ->and($history[2]['request']->getHeaderLine('Idempotency-Key'))
        ->toBe($d->idempotencyKey('subscription.stop'));
});

it('url-encodes the invoice id in changeCycle / stop paths', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => []]),
        jsonResponse(200, ['data' => []]),
    ]);

    $client->subscriptions()->changeCycle('inv with spaces', 'monthly');
    $client->subscriptions()->stop('inv with spaces');

    expect((string) $history[0]['request']->getUri())
        ->toBe('https://vorgio.test/api/v1/invoices/inv%20with%20spaces/change-cycle')
        ->and((string) $history[1]['request']->getUri())
        ->toBe('https://vorgio.test/api/v1/invoices/inv%20with%20spaces/stop-recurring');
});
