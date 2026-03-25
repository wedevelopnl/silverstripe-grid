<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for all grid domain errors.
 * Carries a user-safe message (no IDs or internals) and an HTTP status code.
 */
abstract class GridDomainException extends RuntimeException
{
    public function __construct(
        /** Safe for API responses — contains no IDs or internals. */
        public readonly string $userMessage,
        string $detailedMessage,
        public readonly int $statusCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($detailedMessage, 0, $previous);
    }
}
