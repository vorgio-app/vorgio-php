<?php

declare(strict_types=1);

namespace Vorgio\Resource;

/**
 * `POST /v1/checkouts` — high-level integration endpoint.
 *
 * One call wraps client find-or-create (keyed on
 * `client.external_id`), invoice creation, and the queued send-mail.
 * The response carries the new invoice plus the `mail_event_id`
 * you can later reconcile against the webhook.
 */
class Checkouts extends AbstractResource
{
    /**
     * @param  array<string, mixed>  $payload  The full checkout body — see the
     *   API reference for shape (`client`, `invoice`, `send`, optional
     *   `every` for recurring templates, optional `metadata`).
     * @param  string|null  $operationId  UUIDv7 identifying a higher-level
     *   operation this call is part of. Two calls with the same operation id
     *   produce the same `Idempotency-Key`, so queue retries replay the
     *   cached 2xx instead of generating a fresh one. Pass `null` for
     *   one-shot single-request checkouts (the SDK auto-generates a fresh
     *   UUIDv7 in that case).
     * @return array<string, mixed>
     */
    public function create(array $payload, ?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/checkouts',
            $payload,
            [],
            $this->idempotencyHeader($operationId, 'checkout.create'),
        );
    }
}
