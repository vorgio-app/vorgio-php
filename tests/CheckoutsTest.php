<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Vorgio\Support\OperationDerivation;
use Vorgio\Util\Uuid;

it('POSTs /api/v1/checkouts with the provided body', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, [
            'data' => [
                'client_id' => 'cli_1',
                'invoice' => ['id' => 'inv_1', 'number' => 'INV-2026-0001'],
                'mail_event_id' => 'me_1',
            ],
        ]),
    ]);

    $payload = [
        'client' => [
            'external_id' => 'wc_42',
            'name' => 'Jane Customer',
            'email' => 'jane@example.test',
            'language' => 'de',
        ],
        'invoice' => [
            'tax_rate' => 19,
            'positions' => [['mode' => 'fixed', 'amount_cents' => 9900]],
        ],
        'send' => ['subject' => 'Your invoice', 'body' => 'See attached.'],
        'metadata' => ['order_id' => 'wc_order_999'],
    ];

    $response = $client->checkouts()->create($payload);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/checkouts')
        ->and(json_decode((string) $req->getBody(), true))->toBe($payload)
        ->and($response['data']['invoice']['id'])->toBe('inv_1')
        ->and($response['data']['mail_event_id'])->toBe('me_1');
});

it('derives a stable Idempotency-Key from an operationId', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => []]),
        jsonResponse(201, ['data' => []]),
    ]);

    $opId = Uuid::v7();
    $expectedKey = (new OperationDerivation($opId))->idempotencyKey('checkout.create');

    $client->checkouts()->create(['client' => []], operationId: $opId);
    $client->checkouts()->create(['client' => []], operationId: $opId);

    /** @var Request $first */
    $first = $history[0]['request'];
    /** @var Request $second */
    $second = $history[1]['request'];

    expect($first->getHeaderLine('Idempotency-Key'))->toBe($expectedKey)
        ->and($second->getHeaderLine('Idempotency-Key'))->toBe($expectedKey);
});
