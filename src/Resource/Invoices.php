<?php

declare(strict_types=1);

namespace Vorgio\Resource;

use Vorgio\InvoicePdf;

/**
 * `GET/POST/PATCH/DELETE /v1/invoices` and friends.
 */
class Invoices extends AbstractResource
{
    /**
     * @param  array<string, mixed>  $query  e.g. `['limit' => 25, 'cursor' => '…', 'include' => 'drafts']`
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->request('GET', '/invoices', null, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->request('GET', '/invoices/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $operationId  UUIDv7 identifying a higher-level
     *   operation this call is part of. Two calls with the same operation id
     *   and the same purpose produce the same `Idempotency-Key`, so queue
     *   retries replay the cached 2xx instead of generating a fresh one. Pass
     *   `null` for one-shot single-request POSTs.
     * @return array<string, mixed>
     */
    public function create(array $payload, ?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/invoices',
            $payload,
            [],
            $this->idempotencyHeader($operationId, 'invoice.create'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(string $id, array $payload): array
    {
        return $this->request('PATCH', '/invoices/'.rawurlencode($id), $payload);
    }

    public function delete(string $id): void
    {
        $this->request('DELETE', '/invoices/'.rawurlencode($id));
    }

    /**
     * Queue the invoice email. Returns the server's 202 envelope —
     * `['mail_event_id' => …, 'status' => 'queued']`.
     *
     * @param  array{subject: string, body: string, cc?: array<int, string>}  $payload
     * @return array<string, mixed>
     */
    public function send(string $id, array $payload, ?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/invoices/'.rawurlencode($id).'/send',
            $payload,
            [],
            $this->idempotencyHeader($operationId, 'invoice.send'),
        );
    }

    /**
     * @param  array{paid_at?: string}  $payload
     * @return array<string, mixed>
     */
    public function markPaid(string $id, array $payload = [], ?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/invoices/'.rawurlencode($id).'/mark-paid',
            $payload,
            [],
            $this->idempotencyHeader($operationId, 'invoice.mark-paid'),
        );
    }

    /**
     * Issue a Stornorechnung (German legal cancellation) for a finalised
     * invoice. The original stays in place — required by UStG §14c / GoBD —
     * and the server creates a reversing invoice in its stead.
     *
     * @return array<string, mixed>  The Stornorechnung resource.
     */
    public function cancel(string $id, ?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/invoices/'.rawurlencode($id).'/cancel',
            [],
            [],
            $this->idempotencyHeader($operationId, 'invoice.cancel'),
        );
    }

    /**
     * Download the invoice PDF.
     *
     * Pass the {@see InvoicePdf::$etag} returned from a previous call as
     * `$ifNoneMatch` to let the server short-circuit with 304 when the PDF
     * hasn't changed. The ETag value is opaque — pass it back exactly as
     * received (including surrounding quotes).
     */
    public function pdf(string $id, ?string $ifNoneMatch = null): InvoicePdf
    {
        $headers = [];
        if ($ifNoneMatch !== null) {
            $headers['If-None-Match'] = $ifNoneMatch;
        }

        $raw = $this->client->requestRaw(
            'GET',
            '/invoices/'.rawurlencode($id).'/pdf',
            [],
            $headers,
        );

        $notModified = $raw['status'] === 304;

        return new InvoicePdf(
            bytes: $notModified ? null : $raw['body'],
            etag: $raw['headers']['etag'] ?? '',
            notModified: $notModified,
        );
    }
}
