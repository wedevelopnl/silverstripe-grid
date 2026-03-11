<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\MediaPosition;

#[CoversClass(MediaPosition::class)]
final class MediaPositionTest extends TestCase
{
    public function testIsStringBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(MediaPosition::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function testHasExactlyThreeCases(): void
    {
        $this->assertCount(3, MediaPosition::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testCaseHasExpectedValue(MediaPosition $case, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $case->value);
    }

    public function testAllValuesAreUnique(): void
    {
        $values = array_map(
            static fn (MediaPosition $case): string => $case->value,
            MediaPosition::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    #[DataProvider('caseValueProvider')]
    public function testFromReturnsCorrectCase(MediaPosition $expected, string $value): void
    {
        $this->assertSame($expected, MediaPosition::from($value));
    }

    /**
     * @return iterable<string, array{MediaPosition, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'First' => [MediaPosition::First, 'first'];
        yield 'Last' => [MediaPosition::Last, 'last'];
        yield 'LastOnDesktop' => [MediaPosition::LastOnDesktop, 'last-on-desktop'];
    }
}
