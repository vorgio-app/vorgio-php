<?php

declare(strict_types=1);

namespace Vorgio;

/**
 * Result of `Invoices::pdf()`.
 *
 * On a fresh fetch (HTTP 200), {@see self::$bytes} holds the raw PDF body and
 * {@see self::$etag} the value of the server's ETag header. Pass that ETag
 * back as `ifNoneMatch` on a subsequent call and the server will short-circuit
 * to 304: {@see self::$notModified} becomes true and {@see self::$bytes} is
 * null. The ETag is exposed verbatim (including the surrounding quotes the
 * server returns) so it can be passed straight back without re-quoting.
 */
final class InvoicePdf
{
    public function __construct(
        public readonly ?string $bytes,
        public readonly string $etag,
        public readonly bool $notModified,
    ) {
    }
}
