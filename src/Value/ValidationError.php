<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Structured validation error with optional field context for frontend mapping
 * and optional translation metadata for the i18n layer.
 *
 * When `key` is null, `translate()` returns `message` verbatim. When `key` is
 * set, `translate()` resolves it via SilverStripe's `_t()` with `params` as
 * the injection map and `message` as the English fallback.
 *
 * The `$severity` property is deprecated as of 6.0.0 and will be removed in
 * 7.0.0: nothing reads it and no construction site has ever passed a non-default
 * value. Stop passing it; see {@see ValidationSeverity}.
 */
final readonly class ValidationError
{
    /**
     * @param non-empty-string $message
     * @param non-empty-string|null $field
     * @param non-empty-string|null $key
     * @param array<string, string|int|float> $params
     * @param ValidationSeverity $severity Deprecated since 6.0.0; unread, removal in 7.0.0
     */
    public function __construct(
        public string $message,
        public ?string $field = null,
        public ValidationSeverity $severity = ValidationSeverity::Error,
        public ValidationErrorCode $code = ValidationErrorCode::Generic,
        public ?string $key = null,
        public array $params = [],
    ) {
    }

    public function translate(): string
    {
        if ($this->key === null) {
            return $this->message;
        }

        return _t($this->key, $this->message, $this->params);
    }
}
