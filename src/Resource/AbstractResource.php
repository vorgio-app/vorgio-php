<?php

declare(strict_types=1);

namespace Vorgio\Resource;

use Vorgio\Support\OperationDerivation;
use Vorgio\VorgioClient;

/**
 * Shared plumbing for resource classes — holds the client, exposes a
 * convenience accessor for the raw `request()` method.
 */
abstract class AbstractResource
{
    public function __construct(protected readonly VorgioClient $client)
    {
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
    ): array {
        return $this->client->request($method, $path, $body, $query, $headers);
    }

    /**
     * Compute the `Idempotency-Key` header for a caller-supplied operation id.
     *
     * `$purpose` is a short tag identifying which call inside the operation —
     * `'subscribe'`, `'send'`, `'cancel'`, etc. Distinct purposes ensure that
     * chained POSTs under one operation id don't collide on the same key.
     * Returns an empty array when no operation id was supplied, letting
     * {@see VorgioClient} auto-generate a fresh UUIDv7 per call instead.
     *
     * @return array<string, string>
     */
    protected function idempotencyHeader(?string $operationId, string $purpose): array
    {
        if ($operationId === null) {
            return [];
        }

        return [
            'Idempotency-Key' => (new OperationDerivation($operationId))->idempotencyKey($purpose),
        ];
    }
}
