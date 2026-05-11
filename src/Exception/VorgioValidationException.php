<?php

declare(strict_types=1);

namespace Vorgio\Exception;

use Throwable;

/**
 * Thrown for HTTP 422 responses (validation failures).
 *
 * The {@see self::$errors} property holds the field => messages map exactly
 * as Laravel's validator returned it.
 */
class VorgioValidationException extends VorgioApiException
{
    /**
     * @param  array<string, mixed>  $problem
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors,
        array $problem = [],
        ?string $rawBody = null,
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 422, $problem, $rawBody, $requestId, $previous);
    }
}
