<?php

declare(strict_types=1);

namespace Vorgio\Exception;

use Throwable;

/**
 * Thrown for any non-2xx response from the Vorgio API.
 *
 * The HTTP status code, the parsed RFC 7807 problem document (if the server
 * returned one) and the raw response body are all available so callers can
 * build their own error UX.
 */
class VorgioApiException extends VorgioException
{
    /**
     * @param  array<string, mixed>  $problem  Parsed RFC 7807 body (or empty array)
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $problem = [],
        public readonly ?string $rawBody = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * The `type` URI from the RFC 7807 envelope, or null if absent.
     */
    public function problemType(): ?string
    {
        return isset($this->problem['type']) && is_string($this->problem['type'])
            ? $this->problem['type']
            : null;
    }
}
