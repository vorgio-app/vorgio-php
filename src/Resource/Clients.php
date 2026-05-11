<?php

declare(strict_types=1);

namespace Vorgio\Resource;

/**
 * `GET/POST/PATCH/DELETE /v1/clients`.
 */
class Clients extends AbstractResource
{
    /**
     * @param  array<string, mixed>  $query  e.g. `['limit' => 25, 'cursor' => '…']`
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->request('GET', '/clients', null, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->request('GET', '/clients/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];

        return $this->request('POST', '/clients', $payload, [], $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(string $id, array $payload): array
    {
        return $this->request('PATCH', '/clients/'.rawurlencode($id), $payload);
    }

    public function delete(string $id): void
    {
        $this->request('DELETE', '/clients/'.rawurlencode($id));
    }
}
