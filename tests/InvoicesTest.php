<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Vorgio\Exception\VorgioApiException;
use Vorgio\InvoicePdf;
use Vorgio\Support\OperationDerivation;
use Vorgio\Util\Uuid;

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

it('downloads the invoice PDF', function (): void {
    $pdfBytes = '%PDF-1.4 fake body';
    [$client, $history] = vorgioMockClient([
        new Response(200, [
            'Content-Type' => 'application/pdf',
            'ETag' => '"abc123"',
            'Content-Disposition' => 'inline; filename="invoice-INV-2026-0001.pdf"',
        ], $pdfBytes),
    ]);

    $pdf = $client->invoices()->pdf('inv_1');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($pdf)->toBeInstanceOf(InvoicePdf::class)
        ->and($pdf->bytes)->toBe($pdfBytes)
        ->and($pdf->etag)->toBe('"abc123"')
        ->and($pdf->notModified)->toBeFalse()
        ->and($req->getMethod())->toBe('GET')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1/pdf')
        ->and($req->hasHeader('If-None-Match'))->toBeFalse();
});

it('passes If-None-Match through and returns notModified on 304', function (): void {
    [$client, $history] = vorgioMockClient([
        new Response(304, ['ETag' => '"abc123"'], ''),
    ]);

    $pdf = $client->invoices()->pdf('inv_1', '"abc123"');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($pdf->notModified)->toBeTrue()
        ->and($pdf->bytes)->toBeNull()
        ->and($pdf->etag)->toBe('"abc123"')
        ->and($req->getHeaderLine('If-None-Match'))->toBe('"abc123"');
});

it('throws on 403 when the token lacks invoices:read scope', function (): void {
    [$client] = vorgioMockClient([
        problemResponse(403, 'Forbidden', 'Token is missing the invoices:read scope.'),
    ]);

    expect(fn () => $client->invoices()->pdf('inv_1'))
        ->toThrow(VorgioApiException::class);
});

it('url-encodes the invoice id when fetching the PDF', function (): void {
    [$client, $history] = vorgioMockClient([
        new Response(200, ['Content-Type' => 'application/pdf', 'ETag' => '"x"'], 'pdf'),
    ]);

    $client->invoices()->pdf('inv with spaces');

    /** @var Request $req */
    $req = $history[0]['request'];

    expect((string) $req->getUri())
        ->toBe('https://vorgio.test/api/v1/invoices/inv%20with%20spaces/pdf');
});

it('derives a stable per-purpose Idempotency-Key from an operationId', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => ['id' => 'inv_1']]),
        jsonResponse(202, ['mail_event_id' => 'me_1', 'status' => 'queued']),
    ]);

    $opId = Uuid::v7();
    $d = new OperationDerivation($opId);

    $client->invoices()->create(['client_id' => 'c1'], operationId: $opId);
    $client->invoices()->send('inv_1', ['subject' => 'Hi', 'body' => 'pls pay'], operationId: $opId);

    /** @var Request $first */
    $first = $history[0]['request'];
    /** @var Request $second */
    $second = $history[1]['request'];

    expect($first->getHeaderLine('Idempotency-Key'))->toBe($d->idempotencyKey('invoice.create'))
        ->and($second->getHeaderLine('Idempotency-Key'))->toBe($d->idempotencyKey('invoice.send'))
        // Distinct purposes → distinct keys, so the two calls don't collide.
        ->and($first->getHeaderLine('Idempotency-Key'))->not->toBe($second->getHeaderLine('Idempotency-Key'));
});

it('replays the same Idempotency-Key + body when create() is called twice with the same operationId', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(201, ['data' => ['id' => 'inv_1']]),
        jsonResponse(201, ['data' => ['id' => 'inv_1']]),
    ]);

    $opId = Uuid::v7();
    $payload = ['client_id' => 'c1', 'subject' => 'recurring', 'tax_rate' => 19];

    $client->invoices()->create($payload, operationId: $opId);
    $client->invoices()->create($payload, operationId: $opId);

    /** @var Request $first */
    $first = $history[0]['request'];
    /** @var Request $second */
    $second = $history[1]['request'];

    expect($first->getHeaderLine('Idempotency-Key'))->toBe($second->getHeaderLine('Idempotency-Key'))
        ->and((string) $first->getBody())->toBe((string) $second->getBody());
});

it('POSTs /v1/invoices/{id}/cancel with the operationId-derived Idempotency-Key', function (): void {
    [$client, $history] = vorgioMockClient([
        jsonResponse(200, ['data' => ['id' => 'inv_storno_1', 'kind' => 'storno']]),
    ]);

    $opId = Uuid::v7();
    $response = $client->invoices()->cancel('inv_1', operationId: $opId);

    /** @var Request $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('POST')
        ->and((string) $req->getUri())->toBe('https://vorgio.test/api/v1/invoices/inv_1/cancel')
        ->and($req->getHeaderLine('Idempotency-Key'))
        ->toBe((new OperationDerivation($opId))->idempotencyKey('invoice.cancel'))
        ->and($response['data']['kind'])->toBe('storno');
});
