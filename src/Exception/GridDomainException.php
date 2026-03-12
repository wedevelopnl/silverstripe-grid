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
        private readonly string $userMessage,
        string $detailedMessage,
        private readonly int $statusCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($detailedMessage, 0, $previous);
    }

    /** Safe for API responses — contains no IDs or internals. */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
