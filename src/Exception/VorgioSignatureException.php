<?php

declare(strict_types=1);

namespace Vorgio\Exception;

/**
 * Thrown by {@see \Vorgio\Webhooks::constructEvent()} when an inbound webhook
 * payload fails signature verification — either the signature header is
 * malformed, the HMAC does not match, or the timestamp is outside the
 * tolerance window.
 *
 * Treat this as untrusted input. Log + reject; never re-process.
 */
class VorgioSignatureException extends VorgioException
{
}
