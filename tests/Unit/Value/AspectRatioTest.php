<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\AspectRatio;

#[CoversClass(AspectRatio::class)]
final class AspectRatioTest extends TestCase
{
    public function testIsStringBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(AspectRatio::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function testHasExactlyFourCases(): void
    {
        $this->assertCount(4, AspectRatio::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testCaseHasExpectedValue(AspectRatio $case, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $case->value);
    }

    public function testAllValuesAreUnique(): void
    {
        $values = array_map(
            static fn (AspectRatio $case): string => $case->value,
            AspectRatio::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    #[DataProvider('caseValueProvider')]
    public function testFromReturnsCorrectCase(AspectRatio $expected, string $value): void
    {
        $this->assertSame($expected, AspectRatio::from($value));
    }

    /**
     * @return iterable<string, array{AspectRatio, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'Auto' => [AspectRatio::Auto, 'auto'];
        yield 'Square' => [AspectRatio::Square, '1x1'];
        yield 'FourByThree' => [AspectRatio::FourByThree, '4x3'];
        yield 'SixteenByNine' => [AspectRatio::SixteenByNine, '16x9'];
    }
}
