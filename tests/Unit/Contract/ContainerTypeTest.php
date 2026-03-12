<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(ContainerType::class)]
final class ContainerTypeTest extends TestCase
{
    public function testIsStringBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(ContainerType::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function testHasExactlyThreeCases(): void
    {
        $this->assertCount(3, ContainerType::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testCaseHasExpectedValue(ContainerType $case, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $case->value);
    }

    public function testAllValuesAreUnique(): void
    {
        $values = array_map(
            static fn (ContainerType $case): string => $case->value,
            ContainerType::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    /**
     * @return iterable<string, array{ContainerType, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'Section' => [ContainerType::Section, 'section'];
        yield 'Row' => [ContainerType::Row, 'row'];
        yield 'Column' => [ContainerType::Column, 'column'];
    }

    // ─── childTypeName ──────────────────────────────────────────────

    #[DataProvider('childTypeNameProvider')]
    public function testChildTypeNameReturnsExpectedValue(ContainerType $case, string $expected): void
    {
        $this->assertSame($expected, $case->childTypeName());
    }

    /**
     * @return iterable<string, array{ContainerType, string}>
     */
    public static function childTypeNameProvider(): iterable
    {
        yield 'Section → row' => [ContainerType::Section, 'row'];
        yield 'Row → column' => [ContainerType::Row, 'column'];
        yield 'Column → element' => [ContainerType::Column, 'element'];
    }

    // ─── toElementClass ─────────────────────────────────────────────

    #[DataProvider('toElementClassProvider')]
    public function testToElementClassReturnsExpectedClass(ContainerType $case, string $expected): void
    {
        $this->assertSame($expected, $case->toElementClass());
    }

    /**
     * @return iterable<string, array{ContainerType, string}>
     */
    public static function toElementClassProvider(): iterable
    {
        yield 'Section' => [ContainerType::Section, \WeDevelop\Grid\Model\Section::class];
        yield 'Row' => [ContainerType::Row, \WeDevelop\Grid\Model\Row::class];
        yield 'Column' => [ContainerType::Column, \WeDevelop\Grid\Model\Column::class];
    }
}
