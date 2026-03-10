<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\OffsetStrategy;

#[CoversClass(OffsetStrategy::class)]
final class OffsetStrategyTest extends TestCase
{
    public function testIsStringBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(OffsetStrategy::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function testHasExactlyTwoCases(): void
    {
        $this->assertCount(2, OffsetStrategy::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testCaseHasExpectedValue(OffsetStrategy $case, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $case->value);
    }

    public function testAllValuesAreUnique(): void
    {
        $values = array_map(
            static fn (OffsetStrategy $case): string => $case->value,
            OffsetStrategy::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    /**
     * @return iterable<string, array{OffsetStrategy, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'Margin' => [OffsetStrategy::Margin, 'margin'];
        yield 'GridPlacement' => [OffsetStrategy::GridPlacement, 'grid-placement'];
    }
}
