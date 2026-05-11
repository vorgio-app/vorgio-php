<?php

declare(strict_types=1);

namespace Vorgio\Resource;

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
     * @return array<string, mixed>
     */
    public function create(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];

        return $this->request('POST', '/invoices', $payload, [], $headers);
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
    public function send(string $id, array $payload, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];

        return $this->request(
            'POST',
            '/invoices/'.rawurlencode($id).'/send',
            $payload,
            [],
            $headers,
        );
    }

    /**
     * @param  array{paid_at?: string}  $payload
     * @return array<string, mixed>
     */
    public function markPaid(string $id, array $payload = [], ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];

        return $this->request(
            'POST',
            '/invoices/'.rawurlencode($id).'/mark-paid',
            $payload,
            [],
            $headers,
        );
    }
}
