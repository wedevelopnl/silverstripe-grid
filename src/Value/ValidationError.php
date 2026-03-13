<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Structured validation error with optional field context for frontend mapping.
 */
final readonly class ValidationError
{
    /**
     * @param non-empty-string $message
     * @param non-empty-string|null $field
     */
    public function __construct(
        public string $message,
        public ?string $field = null,
        public ValidationSeverity $severity = ValidationSeverity::Error,
    ) {
    }
}
