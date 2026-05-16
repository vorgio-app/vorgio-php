<?php

declare(strict_types=1);

namespace Vorgio\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Vorgio\Util\Uuid;

/**
 * Single source of truth for deriving correctness primitives from one
 * caller-supplied operation id.
 *
 * A long-running "operation" (an `Activate` action queued for retry, a
 * checkout flow that fires three sequential POSTs, etc.) typically needs:
 *
 *   - A stable, *per-purpose* `Idempotency-Key` header for each underlying
 *     API call so the Vorgio middleware can dedupe retries without false
 *     409s when bodies are bit-identical.
 *   - Stable, *per-position* UUIDs so retries that send the same payload
 *     fill it with the same line-item ids.
 *   - A stable "now" timestamp so retries crossing midnight don't produce
 *     drifted bodies (today vs. tomorrow → different SHA-256 → 409).
 *
 * All three derivations are deterministic functions of one input — a
 * UUIDv7 the caller persists once and replays on retry. Same operation id +
 * same purpose → byte-identical output forever.
 */
final class OperationDerivation
{
    public function __construct(public readonly string $operationId)
    {
        // Validate up front so callers can't accidentally derive from junk.
        Uuid::extractTimestampMs($operationId);
    }

    /**
     * Wrap a caller-supplied operation id, or generate a fresh UUIDv7 when
     * none is provided. The two are interchangeable from this class's POV;
     * the caller's persistence layer decides whether the id is replayable.
     */
    public static function fromOrGenerate(?string $operationId): self
    {
        return new self($operationId ?? Uuid::v7());
    }

    /**
     * Derive the `Idempotency-Key` for one underlying API call.
     *
     * `$purpose` is a short tag identifying *which* call inside the
     * operation: `'subscribe'`, `'send'`, `'cancel'`, etc. Distinct
     * purposes produce distinct keys so multiple POSTs under one operation
     * can each independently dedupe against the Vorgio middleware.
     */
    public function idempotencyKey(string $purpose): string
    {
        return Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, $this->operationId.':'.$purpose);
    }

    /**
     * Derive a deterministic UUID for the n-th line item in a payload.
     */
    public function positionUuid(int $index): string
    {
        return Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, $this->operationId.':position:'.$index);
    }

    /**
     * Decode the UUIDv7's embedded ms-prefix into a UTC `DateTimeImmutable`.
     *
     * Callers should prefer this over `now()` whenever the timestamp must
     * survive a retry: same operation id ⇒ same instant.
     */
    public function snapshotDateTime(): DateTimeImmutable
    {
        $ms = Uuid::extractTimestampMs($this->operationId);
        $seconds = intdiv($ms, 1000);
        $micros = ($ms % 1000) * 1000;

        $dt = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $micros),
            new DateTimeZone('UTC'),
        );

        if ($dt === false) {
            throw new InvalidArgumentException('Operation id timestamp is not a valid instant.');
        }

        return $dt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Convenience: ISO date string (`Y-m-d`) snapshot. Always UTC.
     */
    public function snapshotDateString(): string
    {
        return $this->snapshotDateTime()->format('Y-m-d');
    }
}
