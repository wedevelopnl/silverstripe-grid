<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationSeverity;

#[CoversClass(Result::class)]
#[CoversClass(ValidationError::class)]
#[CoversClass(ValidationSeverity::class)]
final class ResultTest extends TestCase
{
    public function testOkResultIsOk(): void
    {
        $result = Result::ok('value');

        self::assertTrue($result->isOk());
        self::assertFalse($result->isErr());
    }

    public function testOkUnwrapReturnsValue(): void
    {
        $result = Result::ok(42);

        self::assertSame(42, $result->unwrap());
    }

    public function testOkResultHasNoErrors(): void
    {
        $result = Result::ok('anything');

        self::assertSame([], $result->errors());
    }

    public function testOkWithNullValue(): void
    {
        $result = Result::ok(null);

        self::assertTrue($result->isOk());
        self::assertNull($result->unwrap());
    }

    public function testFailResultIsErr(): void
    {
        $error = new ValidationError('Something went wrong');
        $result = Result::fail($error);

        self::assertTrue($result->isErr());
        self::assertFalse($result->isOk());
    }

    public function testFailSingleError(): void
    {
        $error = new ValidationError('Bad input', 'title', ValidationSeverity::Warning);
        $result = Result::fail($error);

        $errors = $result->errors();
        self::assertCount(1, $errors);
        self::assertSame('Bad input', $errors[0]->message);
        self::assertSame('title', $errors[0]->field);
        self::assertSame(ValidationSeverity::Warning, $errors[0]->severity);
    }

    public function testFailMultipleErrors(): void
    {
        $first = new ValidationError('Error one', 'field_a');
        $second = new ValidationError('Error two', 'field_b');
        $third = new ValidationError('Error three');

        $result = Result::fail($first, $second, $third);

        $errors = $result->errors();
        self::assertCount(3, $errors);
        self::assertSame('Error one', $errors[0]->message);
        self::assertSame('Error two', $errors[1]->message);
        self::assertSame('Error three', $errors[2]->message);
    }

    public function testUnwrapOnFailThrowsLogicException(): void
    {
        $result = Result::fail(new ValidationError('fail'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot unwrap a failed Result');

        $result->unwrap();
    }

    public function testMapOnOkTransformsValue(): void
    {
        $result = Result::ok(5);

        $mapped = $result->map(fn (int $value): int => $value * 3);

        self::assertTrue($mapped->isOk());
        self::assertSame(15, $mapped->unwrap());
    }

    public function testMapOnFailPreservesErrors(): void
    {
        $error = new ValidationError('nope');
        $result = Result::fail($error);

        $mapped = $result->map(fn (mixed $value): string => 'should not run');

        self::assertTrue($mapped->isErr());
        self::assertCount(1, $mapped->errors());
        self::assertSame('nope', $mapped->errors()[0]->message);
    }

    public function testMapChaining(): void
    {
        $result = Result::ok(2)
            ->map(fn (int $value): int => $value + 3)
            ->map(fn (int $value): string => "result: {$value}");

        self::assertTrue($result->isOk());
        self::assertSame('result: 5', $result->unwrap());
    }
}
