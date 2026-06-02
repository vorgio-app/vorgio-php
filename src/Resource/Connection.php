<?php

declare(strict_types=1);

namespace Vorgio\Resource;

/**
 * Connection introspection — verify a token's setup without side effects.
 *
 * `retrieve()` returns the team, mode (`live`/`test`), token abilities,
 * billing readiness, and the webhooks registered for the authenticated
 * token's team + mode — everything an "is my Vorgio connection set up
 * correctly?" check needs.
 *
 * `sendTestWebhook()` asks Vorgio to deliver a signed `connection.test`
 * event to your registered webhook endpoint(s); a `2xx` from your receiver
 * proves the shared secret and endpoint URL line up end-to-end.
 */
class Connection extends AbstractResource
{
    /**
     * @return array<string, mixed>
     */
    public function retrieve(): array
    {
        return $this->request('GET', '/connection');
    }

    /**
     * @param  string|null  $operationId  UUIDv7 of a higher-level operation
     *   this call belongs to. Pass `null` for a one-shot test (the SDK
     *   auto-generates a fresh idempotency key).
     * @return array<string, mixed>
     */
    public function sendTestWebhook(?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/connection/test-webhook',
            [],
            [],
            $this->idempotencyHeader($operationId, 'connection.test-webhook'),
        );
    }
}
