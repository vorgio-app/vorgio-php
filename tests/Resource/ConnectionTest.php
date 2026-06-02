<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;

it('retrieves the connection via GET /v1/connection', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, [
            'data' => [
                'team' => ['slug' => 'michael-becker', 'name' => 'Michael Becker'],
                'mode' => 'live',
                'token' => ['name' => 'mvgv', 'abilities' => ['checkouts:write']],
            ],
        ]),
    ]);

    $response = $client->connection()->retrieve();

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('GET')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/connection')
        ->and($response['data']['mode'])->toBe('live')
        ->and($response['data']['team']['slug'])->toBe('michael-becker');
});

it('sends a test webhook via POST /v1/connection/test-webhook with an idempotency key', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, [
            'data' => ['results' => [
                ['url' => 'https://mvgv.test/webhooks/vorgio', 'ok' => true, 'status' => 200, 'error' => null],
            ]],
        ]),
    ]);

    $response = $client->connection()->sendTestWebhook();

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/connection/test-webhook')
        ->and($req->getHeaderLine('Idempotency-Key'))->not->toBe('')
        ->and($response['data']['results'][0]['ok'])->toBeTrue();
});
