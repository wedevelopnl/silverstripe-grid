<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\OverrideStrategy;

#[CoversClass(OverrideStrategy::class)]
final class OverrideStrategyTest extends TestCase
{
    public function testIsStringBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(OverrideStrategy::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function testHasExactlyTwoCases(): void
    {
        $this->assertCount(2, OverrideStrategy::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testCaseHasExpectedValue(OverrideStrategy $case, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $case->value);
    }

    public function testAllValuesAreUnique(): void
    {
        $values = array_map(
            static fn (OverrideStrategy $case): string => $case->value,
            OverrideStrategy::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    /**
     * @return iterable<string, array{OverrideStrategy, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'Isolated' => [OverrideStrategy::Isolated, 'isolated'];
        yield 'Cascade' => [OverrideStrategy::Cascade, 'cascade'];
    }
}
