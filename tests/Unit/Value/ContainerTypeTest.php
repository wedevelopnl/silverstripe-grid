<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(ContainerType::class)]
final class ContainerTypeTest extends TestCase
{
    /**
     * @return array<string, array{ContainerType, string}>
     */
    public static function childTypeNameProvider(): array
    {
        return [
            'Section child type is row' => [ContainerType::Section, 'row'],
            'Row child type is column' => [ContainerType::Row, 'column'],
            'Column child type is element' => [ContainerType::Column, 'element'],
        ];
    }

    #[DataProvider('childTypeNameProvider')]
    public function testChildTypeName(ContainerType $type, string $expected): void
    {
        self::assertSame($expected, $type->childTypeName());
    }

    /**
     * @return array<string, array{ContainerType, class-string}>
     */
    public static function toElementClassProvider(): array
    {
        return [
            'Section maps to Section model' => [ContainerType::Section, Section::class],
            'Row maps to Row model' => [ContainerType::Row, Row::class],
            'Column maps to Column model' => [ContainerType::Column, Column::class],
        ];
    }

    #[DataProvider('toElementClassProvider')]
    public function testToElementClass(ContainerType $type, string $expected): void
    {
        self::assertSame($expected, $type->toElementClass());
    }

    /**
     * @return array<string, array{ContainerType, bool}>
     */
    public static function canBeRootProvider(): array
    {
        return [
            'Section can be root' => [ContainerType::Section, true],
            'Row cannot be root' => [ContainerType::Row, false],
            'Column cannot be root' => [ContainerType::Column, false],
        ];
    }

    #[DataProvider('canBeRootProvider')]
    public function testCanBeRoot(ContainerType $type, bool $expected): void
    {
        self::assertSame($expected, $type->canBeRoot());
    }

    /**
     * @return array<string, array{ContainerType, class-string|null}>
     */
    public static function allowedChildClassProvider(): array
    {
        return [
            'Section allows Row' => [ContainerType::Section, Row::class],
            'Row allows Column' => [ContainerType::Row, Column::class],
            'Column has no single allowed class' => [ContainerType::Column, null],
        ];
    }

    #[DataProvider('allowedChildClassProvider')]
    public function testAllowedChildClass(ContainerType $type, ?string $expected): void
    {
        self::assertSame($expected, $type->allowedChildClass());
    }

    /**
     * @return array<string, array{ContainerType, class-string, bool}>
     */
    public static function isChildAllowedProvider(): array
    {
        return [
            'Section allows Row' => [ContainerType::Section, Row::class, true],
            'Section rejects Column' => [ContainerType::Section, Column::class, false],
            'Section rejects Section' => [ContainerType::Section, Section::class, false],
            'Section rejects ContentElement' => [ContainerType::Section, ContentElement::class, false],
            'Row allows Column' => [ContainerType::Row, Column::class, true],
            'Row rejects Row' => [ContainerType::Row, Row::class, false],
            'Row rejects Section' => [ContainerType::Row, Section::class, false],
            'Row rejects ContentElement' => [ContainerType::Row, ContentElement::class, false],
            'Column allows ContentElement' => [ContainerType::Column, ContentElement::class, true],
            'Column allows GridElement' => [ContainerType::Column, GridElement::class, true],
            'Column rejects Section' => [ContainerType::Column, Section::class, false],
            'Column rejects Row' => [ContainerType::Column, Row::class, false],
            'Column rejects Column' => [ContainerType::Column, Column::class, false],
        ];
    }

    #[DataProvider('isChildAllowedProvider')]
    public function testIsChildAllowed(ContainerType $type, string $elementClass, bool $expected): void
    {
        self::assertSame($expected, $type->isChildAllowed($elementClass));
    }
}
