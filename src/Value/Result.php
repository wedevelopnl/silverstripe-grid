<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use NoDiscard;
use LogicException;

/**
 * Use Result::ok($value) for success and Result::fail($errors...) for expected failures.
 * Exceptions remain for truly exceptional situations (bugs, infrastructure failures).
 *
 * @template-covariant T
 */
final readonly class Result
{
    /**
     * @param T $value
     * @param list<ValidationError> $errors
     */
    private function __construct(
        private bool $ok,
        private mixed $value,
        private array $errors,
    ) {
    }

    /**
     * @template U
     * @param U $value
     * @return self<U>
     */
    #[NoDiscard('A Result exists to be inspected (isOk/isErr/unwrap); discarding it defeats its purpose.')]
    public static function ok(mixed $value): self
    {
        return new self(ok: true, value: $value, errors: []);
    }

    /**
     * @return self<never>
     */
    #[NoDiscard('A failed Result must be surfaced to the caller; discarding it silently swallows the errors.')]
    public static function fail(ValidationError $first, ValidationError ...$rest): self
    {
        /** @var list<ValidationError> $errors Variadic ...$rest is always a list */
        $errors = [$first, ...$rest];

        /** @var self<never> Safe: failed Results never expose their value via unwrap() */
        $result = new self(ok: false, value: null, errors: $errors);

        return $result;
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isErr(): bool
    {
        return !$this->ok;
    }

    /**
     * @return T
     * @throws LogicException If called on a failed Result (programmer bug)
     */
    public function unwrap(): mixed
    {
        if (!$this->ok) {
            throw new LogicException('Cannot unwrap a failed Result');
        }

        return $this->value;
    }

    /** @return list<ValidationError> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Transform the success value. No-op on failure.
     *
     * @template U
     * @param callable(T): U $fn
     * @return self<U>
     */
    #[NoDiscard('map() returns a new Result; discarding it loses the transformation and any errors.')]
    public function map(callable $fn): self
    {
        if (!$this->ok) {
            // Reconstruct instead of returning $this so PHPStan can narrow self<T> → self<U>
            /** @var self<U> */
            $result = new self(ok: false, value: null, errors: $this->errors);

            return $result;
        }

        return self::ok($fn($this->value));
    }
}
