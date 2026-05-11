<?php

declare(strict_types=1);

namespace Vorgio;

/**
 * Decoded, signature-verified webhook event.
 *
 * Returned by {@see Webhooks::constructEvent()} after the HMAC + timestamp
 * check passes. The original JSON payload is preserved on {@see self::$rawPayload}
 * for re-signing or audit logging.
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $data  The `data` envelope (e.g. `['invoice' => …, 'client' => …]`)
     * @param  array<string, mixed>  $metadata  The merchant-supplied metadata round-tripped from the original /v1/checkouts call
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $createdAt,
        public readonly array $data,
        public readonly array $metadata,
        public readonly string $rawPayload,
    ) {
    }
}
