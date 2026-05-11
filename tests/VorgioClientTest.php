<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Vorgio\Exception\VorgioApiException;
use Vorgio\Exception\VorgioException;
use Vorgio\Exception\VorgioRateLimitedException;
use Vorgio\Exception\VorgioValidationException;
use Vorgio\VorgioClient;

it('rejects empty tokens', function (): void {
    new VorgioClient(token: '');
})->throws(VorgioException::class, 'cannot be empty');

it('attaches Authorization, Accept and User-Agent headers', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => []]),
    ]);

    $client->request('GET', '/clients');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getHeaderLine('Authorization'))->toBe('Bearer act_test')
        ->and($req->getHeaderLine('Accept'))->toBe('application/json')
        ->and($req->getHeaderLine('User-Agent'))->toStartWith('vorgio-php/');
});

it('auto-generates an Idempotency-Key UUIDv7 for POST when caller did not supply one', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => ['id' => 'inv_1']]),
    ]);

    $client->request('POST', '/invoices', ['client_id' => 'c1']);

    /** @var Request $req */
    $req = $history[0]['request'];
    $key = $req->getHeaderLine('Idempotency-Key');

    expect($key)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('honours a caller-supplied Idempotency-Key', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => []]),
    ]);

    $client->request('POST', '/invoices', body: ['x' => 1], headers: ['Idempotency-Key' => 'my-key-1']);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getHeaderLine('Idempotency-Key'))->toBe('my-key-1');
});

it('does not attach Idempotency-Key on GET', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => []]),
    ]);

    $client->request('GET', '/clients');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getHeaderLine('Idempotency-Key'))->toBe('');
});

it('builds /api/v1/* URLs from a relative path', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => []]),
    ]);

    $client->request('GET', '/clients');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect((string) $req->getUri())->toBe('https://vorgio.test/api/v1/clients');
});

it('passes the body through as JSON', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => []]),
    ]);

    $client->request('POST', '/clients', ['name' => 'Acme', 'country' => 'DE']);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect((string) $req->getBody())->toBe('{"name":"Acme","country":"DE"}')
        ->and($req->getHeaderLine('Content-Type'))->toBe('application/json');
});

it('decodes 2xx JSON responses', function (): void {
    [$client] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'cli_42']]),
    ]);

    expect($client->request('GET', '/clients/cli_42'))
        ->toBe(['data' => ['id' => 'cli_42']]);
});

it('throws VorgioValidationException with structured errors on 422', function (): void {
    [$client] = vorgioMockClient([
        problemResponse(
            422,
            'Validation failed',
            'The given data was invalid.',
            ['errors' => ['client.email' => ['Required.']]],
        ),
    ]);

    try {
        $client->request('POST', '/checkouts', ['x' => 1]);
        expect(true)->toBeFalse('expected exception');
    } catch (VorgioValidationException $e) {
        expect($e->getCode())->toBe(422)
            ->and($e->errors)->toBe(['client.email' => ['Required.']])
            ->and($e->problemType())->toContain('validation-failed');
    }
});

it('throws VorgioRateLimitedException with retryAfter on 429', function (): void {
    [$client] = vorgioMockClient([
        problemResponse(429, 'Too Many Requests', '', [], ['Retry-After' => '17']),
    ]);

    try {
        $client->request('POST', '/checkouts', ['x' => 1]);
        expect(true)->toBeFalse('expected exception');
    } catch (VorgioRateLimitedException $e) {
        expect($e->retryAfter)->toBe(17)
            ->and($e->getCode())->toBe(429);
    }
});

it('falls back to a 60s retryAfter when the header is missing', function (): void {
    [$client] = vorgioMockClient([
        problemResponse(429, 'Too Many Requests'),
    ]);

    try {
        $client->request('GET', '/clients');
        expect(true)->toBeFalse('expected exception');
    } catch (VorgioRateLimitedException $e) {
        expect($e->retryAfter)->toBe(60);
    }
});

it('throws generic VorgioApiException for other 4xx/5xx', function (): void {
    [$client] = vorgioMockClient([
        problemResponse(404, 'Not Found', 'No such client.'),
    ]);

    try {
        $client->request('GET', '/clients/missing');
        expect(true)->toBeFalse('expected exception');
    } catch (VorgioApiException $e) {
        expect($e->getCode())->toBe(404)
            ->and($e->getMessage())->toContain('Not Found')
            ->and($e->getMessage())->toContain('No such client.');
    }
});

it('captures the X-Request-Id header on errors', function (): void {
    [$client] = vorgioMockClient([
        problemResponse(500, 'Server Error', '', [], ['X-Request-Id' => 'req_abc123']),
    ]);

    try {
        $client->request('GET', '/clients');
        expect(true)->toBeFalse('expected exception');
    } catch (VorgioApiException $e) {
        expect($e->requestId)->toBe('req_abc123');
    }
});
