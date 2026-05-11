<?php

declare(strict_types=1);

namespace Vorgio\Exception;

use Throwable;

/**
 * Thrown for HTTP 429 responses.
 *
 * Use {@see self::$retryAfter} to schedule a retry — the SDK does not retry
 * automatically because retry policy is application-specific (queue, cron,
 * inline…) and a stuck retry loop is worse than a surfaced error.
 */
class VorgioRateLimitedException extends VorgioApiException
{
    /**
     * @param  array<string, mixed>  $problem
     */
    public function __construct(
        string $message,
        public readonly int $retryAfter,
        array $problem = [],
        ?string $rawBody = null,
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $problem, $rawBody, $requestId, $previous);
    }
}
