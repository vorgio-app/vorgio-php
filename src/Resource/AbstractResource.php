<?php

declare(strict_types=1);

namespace Vorgio\Resource;

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
}
