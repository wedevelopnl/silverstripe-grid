<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\VerticalAlignment;

#[CoversClass(VerticalAlignment::class)]
final class VerticalAlignmentTest extends TestCase
{
    public function testIsStringBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(VerticalAlignment::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function testHasExactlyThreeCases(): void
    {
        $this->assertCount(3, VerticalAlignment::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testCaseHasExpectedValue(VerticalAlignment $case, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $case->value);
    }

    public function testAllValuesAreUnique(): void
    {
        $values = array_map(
            static fn (VerticalAlignment $case): string => $case->value,
            VerticalAlignment::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    #[DataProvider('caseValueProvider')]
    public function testFromReturnsCorrectCase(VerticalAlignment $expected, string $value): void
    {
        $this->assertSame($expected, VerticalAlignment::from($value));
    }

    /**
     * @return iterable<string, array{VerticalAlignment, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'Top' => [VerticalAlignment::Top, 'top'];
        yield 'Center' => [VerticalAlignment::Center, 'center'];
        yield 'Bottom' => [VerticalAlignment::Bottom, 'bottom'];
    }
}
