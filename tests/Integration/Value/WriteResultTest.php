<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Value\WriteResult;

#[CoversClass(WriteResult::class)]
final class WriteResultTest extends SapphireTest
{
    public function testFromReturnsOkOnSuccess(): void
    {
        $result = WriteResult::from(static fn (): int => 42);

        self::assertTrue($result->isOk());
        self::assertSame(42, $result->unwrap());
    }

    public function testFromCatchesValidationException(): void
    {
        $result = WriteResult::from(static function (): never {
            throw new ValidationException('Write failed');
        });

        self::assertTrue($result->isErr());
    }

    public function testTranslatesExceptionMessages(): void
    {
        $validationResult = ValidationResult::create();
        $validationResult->addFieldError('Title', 'Title is required');

        $result = WriteResult::from(static function () use ($validationResult): never {
            throw new ValidationException($validationResult);
        });

        self::assertTrue($result->isErr());
        $errors = $result->errors();
        self::assertCount(1, $errors);
        self::assertSame('Title is required', $errors[0]->message);
        self::assertSame('Title', $errors[0]->field);
    }

    public function testTranslatesAllFieldErrors(): void
    {
        // Pins that every message is translated into its own ValidationError —
        // a mutant that emits only the first (or last) entry would fail the count.
        $validationResult = ValidationResult::create();
        $validationResult->addFieldError('Title', 'Title is required');
        $validationResult->addFieldError('Zone', 'Zone is invalid');

        $result = WriteResult::from(static function () use ($validationResult): never {
            throw new ValidationException($validationResult);
        });

        self::assertTrue($result->isErr());
        $errors = $result->errors();
        self::assertCount(2, $errors);

        $messages = array_map(static fn ($error): string => $error->message, $errors);
        self::assertContains('Title is required', $messages);
        self::assertContains('Zone is invalid', $messages);
    }

    public function testEmptyExceptionMessagesFallback(): void
    {
        // ValidationResult with no messages
        $validationResult = ValidationResult::create();

        $result = WriteResult::from(static function () use ($validationResult): never {
            throw new ValidationException($validationResult);
        });

        self::assertTrue($result->isErr());
        $errors = $result->errors();
        self::assertCount(1, $errors);
        self::assertSame('Validation failed.', $errors[0]->message);
    }

    public function testNonValidationExceptionBubbles(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a validation error');

        WriteResult::from(static function (): never {
            throw new \RuntimeException('Not a validation error');
        });
    }
}
