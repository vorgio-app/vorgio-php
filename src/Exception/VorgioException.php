<?php

declare(strict_types=1);

namespace Vorgio\Exception;

use RuntimeException;

/**
 * Base exception for everything thrown by the Vorgio SDK.
 *
 * Catch this when you want a single coarse-grained handler — catch one of the
 * subclasses ({@see VorgioApiException}, {@see VorgioSignatureException}…)
 * when you need to react to a specific failure mode.
 */
class VorgioException extends RuntimeException
{
}
