<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Exception;

final class InvalidGridValueException extends GridDomainException
{
    private const int STATUS_CODE = 422;

    public static function forViewport(string $key): self
    {
        return new self(
            userMessage: 'The specified viewport is not recognised.',
            detailedMessage: sprintf('Viewport key "%s" is not a valid breakpoint.', $key),
            statusCode: self::STATUS_CODE,
        );
    }

    public static function forEmptyViewports(): self
    {
        return new self(
            userMessage: 'At least one viewport must be enabled.',
            detailedMessage: 'The enabled_viewports configuration cannot be an empty array.',
            statusCode: self::STATUS_CODE,
        );
    }

    public static function forContainerMaxWidth(int $value): self
    {
        return new self(
            userMessage: 'The configured container max width is invalid.',
            detailedMessage: sprintf('Container max width must be positive, got %d.', $value),
            statusCode: self::STATUS_CODE,
        );
    }

    public static function forColumnCount(mixed $value): self
    {
        return new self(
            userMessage: 'The configured column count is invalid.',
            detailedMessage: sprintf(
                'Column count must be a positive integer, got %s (%s).',
                var_export($value, true),
                get_debug_type($value),
            ),
            statusCode: self::STATUS_CODE,
        );
    }

    public static function forMalformedViewportPayload(string $context, string $reason): self
    {
        return new self(
            userMessage: 'The grid settings payload is malformed.',
            detailedMessage: sprintf('Malformed viewport payload in %s: %s', $context, $reason),
            statusCode: self::STATUS_CODE,
        );
    }

    public static function forOverrideStrategy(string $value): self
    {
        return new self(
            userMessage: 'The configured override strategy is invalid.',
            detailedMessage: sprintf(
                'Override strategy must be "isolated" or "cascade", got "%s".',
                $value,
            ),
            statusCode: self::STATUS_CODE,
        );
    }
}
