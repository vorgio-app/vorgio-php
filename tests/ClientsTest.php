<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;

it('lists clients', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, [
            'data' => [['id' => 'cli_1', 'name' => 'Acme']],
            'meta' => ['next_cursor' => null],
        ]),
    ]);

    $response = $client->clients()->list(['limit' => 50]);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('GET')
        ->and($req->getUri()->getQuery())->toContain('limit=50')
        ->and($response['data'][0]['name'])->toBe('Acme');
});

it('creates a client', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => ['id' => 'cli_2', 'name' => 'New Co']]),
    ]);

    $body = ['name' => 'New Co', 'address' => 'Unter den Linden 1', 'country' => 'DE'];
    $client->clients()->create($body);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/clients')
        ->and(json_decode((string) $req->getBody(), true))->toBe($body);
});

it('updates a client via PATCH', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'cli_2']]),
    ]);

    $client->clients()->update('cli_2', ['email' => 'new@example.test']);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('PATCH')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/clients/cli_2');
});
