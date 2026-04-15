<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilverStripe\CMS\Model\SiteTree;
use stdClass;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\NodeType;

#[CoversClass(NodeType::class)]
final class NodeTypeTest extends TestCase
{
    /**
     * @return array<string, array{class-string, NodeType}>
     */
    public static function fromClassProvider(): array
    {
        return [
            'SiteTree → Page' => [SiteTree::class, NodeType::Page],
            'Section → Section' => [Section::class, NodeType::Section],
            'Row → Row' => [Row::class, NodeType::Row],
            'Column → Column' => [Column::class, NodeType::Column],
            'ContentElement → Element' => [ContentElement::class, NodeType::Element],
            'Abstract GridElement → Element' => [GridElement::class, NodeType::Element],
        ];
    }

    #[DataProvider('fromClassProvider')]
    public function testFromClass(string $class, NodeType $expected): void
    {
        self::assertSame($expected, NodeType::fromClass($class));
    }

    public function testFromClassThrowsForUnrelatedClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NodeType::fromClass(stdClass::class);
    }

    /**
     * @return array<string, array{NodeType, class-string}>
     */
    public static function toClassProvider(): array
    {
        return [
            'Page → SiteTree' => [NodeType::Page, SiteTree::class],
            'Section → Section' => [NodeType::Section, Section::class],
            'Row → Row' => [NodeType::Row, Row::class],
            'Column → Column' => [NodeType::Column, Column::class],
            'Element → GridElement' => [NodeType::Element, GridElement::class],
        ];
    }

    #[DataProvider('toClassProvider')]
    public function testToClass(NodeType $type, string $expected): void
    {
        self::assertSame($expected, $type->toClass());
    }

    /**
     * @return array<string, array{NodeType, bool}>
     */
    public static function isContainerProvider(): array
    {
        return [
            'Page is not a container' => [NodeType::Page, false],
            'Section is a container' => [NodeType::Section, true],
            'Row is a container' => [NodeType::Row, true],
            'Column is a container' => [NodeType::Column, true],
            'Element is not a container' => [NodeType::Element, false],
        ];
    }

    #[DataProvider('isContainerProvider')]
    public function testIsContainer(NodeType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isContainer());
    }

    /**
     * @return array<string, array{NodeType, bool}>
     */
    public static function isDraggableProvider(): array
    {
        return [
            'Page is not draggable' => [NodeType::Page, false],
            'Section is draggable' => [NodeType::Section, true],
            'Row is draggable' => [NodeType::Row, true],
            'Column is draggable' => [NodeType::Column, true],
            'Element is draggable' => [NodeType::Element, true],
        ];
    }

    #[DataProvider('isDraggableProvider')]
    public function testIsDraggable(NodeType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isDraggable());
    }
}
