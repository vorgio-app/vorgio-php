<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;

it('lists invoices with cursor + include params', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => [], 'meta' => ['next_cursor' => null]]),
    ]);

    $client->invoices()->list(['limit' => 25, 'include' => 'drafts', 'cursor' => 'abc']);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('GET')
        ->and($req->getUri()->getQuery())->toContain('limit=25')
        ->and($req->getUri()->getQuery())->toContain('include=drafts')
        ->and($req->getUri()->getQuery())->toContain('cursor=abc');
});

it('retrieves an invoice by id', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'inv_1']]),
    ]);

    $client->invoices()->retrieve('inv_1');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1');
});

it('url-encodes ids that contain awkward characters', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => []]),
    ]);

    $client->invoices()->retrieve('inv with spaces');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect((string) $req->getUri())
        ->toBe('https://vorgio.test/api/v1/invoices/inv%20with%20spaces');
});

it('sends an invoice via POST /invoices/{id}/send', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(202, ['mail_event_id' => 'me_1', 'status' => 'queued']),
    ]);

    $client->invoices()->send('inv_1', ['subject' => 'Hi', 'body' => 'pls pay']);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1/send')
        ->and(json_decode((string) $req->getBody(), true))->toBe(['subject' => 'Hi', 'body' => 'pls pay']);
});

it('marks an invoice paid', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'inv_1', 'paid_at' => '2026-05-10']]),
    ]);

    $client->invoices()->markPaid('inv_1', ['paid_at' => '2026-05-10']);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1/mark-paid')
        ->and(json_decode((string) $req->getBody(), true))->toBe(['paid_at' => '2026-05-10']);
});

it('mark-paid works without a body', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => []]),
    ]);

    $client->invoices()->markPaid('inv_1');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect((string) $req->getBody())->toBe('[]');
});

it('deletes an invoice', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(204, []),
    ]);

    $client->invoices()->delete('inv_1');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('DELETE')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1');
});
