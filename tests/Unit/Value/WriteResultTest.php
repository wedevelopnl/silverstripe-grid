<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use WeDevelop\Grid\Value\WriteResult;

#[CoversClass(WriteResult::class)]
final class WriteResultTest extends TestCase
{
    public function testFromReturnsOkOnSuccess(): void
    {
        $result = WriteResult::from(static fn (): string => 'value');

        $this->assertTrue($result->isOk());
        $this->assertSame('value', $result->unwrap());
    }

    public function testFromReturnsFailOnValidationException(): void
    {
        $exception = $this->createValidationException([
            ['message' => 'Row cannot be placed inside Page.', 'fieldName' => ''],
        ]);

        $result = WriteResult::from(static function () use ($exception): never {
            throw $exception;
        });

        $this->assertTrue($result->isErr());
        $this->assertCount(1, $result->errors());
        $this->assertSame('Row cannot be placed inside Page.', $result->errors()[0]->message);
    }

    public function testFromTranslatesMultipleValidationErrors(): void
    {
        $exception = $this->createValidationException([
            ['message' => 'First error.', 'fieldName' => ''],
            ['message' => 'Second error.', 'fieldName' => ''],
        ]);

        $result = WriteResult::from(static function () use ($exception): never {
            throw $exception;
        });

        $this->assertCount(2, $result->errors());
        $this->assertSame('First error.', $result->errors()[0]->message);
        $this->assertSame('Second error.', $result->errors()[1]->message);
    }

    public function testFromPreservesFieldNameFromValidation(): void
    {
        $exception = $this->createValidationException([
            ['message' => 'Invalid parent.', 'fieldName' => 'ParentID'],
        ]);

        $result = WriteResult::from(static function () use ($exception): never {
            throw $exception;
        });

        $this->assertTrue($result->isErr());
        $this->assertCount(1, $result->errors());
        $this->assertSame('Invalid parent.', $result->errors()[0]->message);
        $this->assertSame('ParentID', $result->errors()[0]->field);
    }

    public function testFromMapsEmptyFieldNameToNull(): void
    {
        $exception = $this->createValidationException([
            ['message' => 'General error.', 'fieldName' => ''],
        ]);

        $result = WriteResult::from(static function () use ($exception): never {
            throw $exception;
        });

        $this->assertNull($result->errors()[0]->field);
    }

    public function testFromFallbackMessageWhenNoValidationMessages(): void
    {
        $exception = $this->createValidationException([]);

        $result = WriteResult::from(static function () use ($exception): never {
            throw $exception;
        });

        $this->assertTrue($result->isErr());
        $this->assertCount(1, $result->errors());
        $this->assertSame('Validation failed.', $result->errors()[0]->message);
    }

    /**
     * Build a mocked ValidationException with the given message list.
     *
     * Avoids constructing a real ValidationException which requires the
     * SilverStripe kernel (Injector, i18n) that is unavailable in unit tests.
     *
     * @param list<array{message: string, fieldName: string}> $messages
     */
    private function createValidationException(array $messages): ValidationException
    {
        $validationResult = $this->createMock(ValidationResult::class);
        $validationResult->method('getMessages')->willReturn($messages);

        $exception = $this->createMock(ValidationException::class);
        $exception->method('getResult')->willReturn($validationResult);

        return $exception;
    }
}
