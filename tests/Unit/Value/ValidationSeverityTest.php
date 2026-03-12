<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ValidationSeverity;

#[CoversClass(ValidationSeverity::class)]
final class ValidationSeverityTest extends TestCase
{
    public function testIsStringBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(ValidationSeverity::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function testHasExactlyTwoCases(): void
    {
        $this->assertCount(2, ValidationSeverity::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testCaseHasExpectedValue(ValidationSeverity $case, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $case->value);
    }

    public function testAllValuesAreUnique(): void
    {
        $values = array_map(
            static fn (ValidationSeverity $case): string => $case->value,
            ValidationSeverity::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    #[DataProvider('caseValueProvider')]
    public function testFromReturnsCorrectCase(ValidationSeverity $expected, string $value): void
    {
        $this->assertSame($expected, ValidationSeverity::from($value));
    }

    /**
     * @return iterable<string, array{ValidationSeverity, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'Error' => [ValidationSeverity::Error, 'error'];
        yield 'Warning' => [ValidationSeverity::Warning, 'warning'];
    }
}
