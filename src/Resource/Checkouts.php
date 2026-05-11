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
     *   API reference for shape (`client`, `invoice`, `send`, optional `metadata`).
     * @param  string|null  $idempotencyKey  Optional. SDK auto-generates a UUIDv7
     *   when omitted, which is correct for *new* checkouts. Pass an explicit
     *   key when you want safe replays.
     * @return array<string, mixed>
     */
    public function create(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->request('POST', '/checkouts', $payload, [], $headers);
    }
}
