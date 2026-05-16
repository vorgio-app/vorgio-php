<?php

declare(strict_types=1);

namespace Vorgio\Support;

use InvalidArgumentException;

/**
 * Tiny retry policy for transient network failures and 5xx responses.
 *
 * Vorgio's `Idempotency` middleware caches the first 2xx response per
 * `Idempotency-Key` for 24h, so re-sending the same key + body on retry is
 * safe by design: a successful follow-up returns the cached 2xx, no double
 * side-effect.
 *
 * 4xx is *never* retried — including 422 (validation) and 429 (rate-limit,
 * which has its own caller-driven `Retry-After` flow via the dedicated
 * exception type).
 */
final class RetryPolicy
{
    /**
     * Default backoff schedule (ms), indexed by attempt number (0-based).
     *
     * Three retries → four attempts total. Numbers are small constants on
     * purpose; this isn't a queue-style retry loop, just a guard against
     * the network blinking.
     */
    public const DEFAULT_BACKOFF_MS = [200, 800, 3200];

    /** @var list<int> */
    public readonly array $backoffMs;

    /**
     * @param  array<int, int>|null  $backoffMs  Custom backoff schedule in ms.
     *                                           Length determines the retry cap
     *                                           (defaults to {@see DEFAULT_BACKOFF_MS}).
     */
    public function __construct(
        public readonly bool $enabled = true,
        ?array $backoffMs = null,
    ) {
        $schedule = $backoffMs ?? self::DEFAULT_BACKOFF_MS;

        foreach ($schedule as $ms) {
            if ($ms < 0) {
                throw new InvalidArgumentException('Backoff delays must be non-negative.');
            }
        }

        $this->backoffMs = array_values($schedule);
    }

    /**
     * A policy that performs no retries at all (used by tests and by callers
     * that want one-shot semantics).
     */
    public static function disabled(): self
    {
        return new self(enabled: false, backoffMs: []);
    }

    public function maxAttempts(): int
    {
        return $this->enabled ? count($this->backoffMs) : 0;
    }

    /**
     * Should the request be retried after seeing this status code?
     *
     * `null` indicates a transport-level failure (connect refused, DNS,
     * timeout) — those are always retryable.
     */
    public function shouldRetry(?int $status, int $attempt): bool
    {
        if (! $this->enabled || $attempt >= $this->maxAttempts()) {
            return false;
        }

        if ($status === null) {
            return true;
        }

        return $status >= 500 && $status <= 599;
    }

    public function delayMs(int $attempt): int
    {
        return $this->backoffMs[$attempt] ?? 0;
    }
}
