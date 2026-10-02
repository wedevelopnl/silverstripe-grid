<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Value\GridSettings;

/**
 * Static factory for creating grid elements with correct polymorphic parent wiring.
 *
 * Each method creates one element, assigns the polymorphic Parent relationship,
 * writes it to the database, and returns the persisted instance.
 *
 * Sort defaults to 0, which lets GridElement::ensureSortSet() assign the next
 * sequential value automatically. Pass an explicit Sort to override.
 */
final class GridTreeFactory
{
    /**
     * $parent is a SiteTree page, or a SharedBlock when the section roots a shared subtree.
     * $zone defaults to what the position requires: 'main' on a page, none elsewhere.
     */
    public static function section(DataObject $parent, ?string $zone = null, int $sort = 0, string $title = ''): Section
    {
        $section = Section::create();
        $section->Title = $title;
        $section->Zone = $zone ?? self::defaultZoneFor($parent);
        $section->Sort = $sort;
        $section->ParentID = $parent->ID;
        $section->ParentClass = $parent::class;
        $section->write();

        return $section;
    }

    /** Keeps the factory from producing rows the zone rule refuses. */
    private static function defaultZoneFor(DataObject $parent): string
    {
        return $parent instanceof SiteTree ? 'main' : '';
    }

    public static function sharedBlock(string $title = 'Shared block'): SharedBlock
    {
        $block = SharedBlock::create();
        $block->Title = $title;
        $block->write();

        return $block;
    }

    /**
     * $parent is the placement target: a page (zone applies) or a container element.
     * $zone defaults as for {@see section()}.
     */
    public static function reference(
        DataObject $parent,
        SharedBlock $block,
        ?string $zone = null,
        int $sort = 0,
        string $title = '',
    ): SharedBlockReference {
        $reference = SharedBlockReference::create();
        $reference->Title = $title;
        $reference->Zone = $zone ?? self::defaultZoneFor($parent);
        $reference->Sort = $sort;
        $reference->BlockID = $block->ID;
        $reference->ParentID = $parent->ID;
        $reference->ParentClass = $parent::class;
        $reference->write();

        return $reference;
    }

    public static function row(Section $section, int $sort = 0, string $title = ''): Row
    {
        $row = Row::create();
        $row->Title = $title;
        $row->Sort = $sort;
        $row->ParentID = $section->ID;
        $row->ParentClass = $section::class;
        $row->write();

        return $row;
    }

    public static function column(Row $row, int $sort = 0, ?GridSettings $gridSettings = null, string $title = ''): Column
    {
        $column = Column::create();
        $column->Title = $title;
        $column->Sort = $sort;
        $column->ParentID = $row->ID;
        $column->ParentClass = $row::class;

        if ($gridSettings !== null) {
            $column->setGridSettings($gridSettings);
        }

        $column->write();

        return $column;
    }

    /**
     * Build the full container ancestry in one call: Section > Row > Column.
     *
     * @return array{section: Section, row: Row, column: Column}
     */
    public static function containerTree(SiteTree $page, string $zone = 'main'): array
    {
        $section = self::section($page, $zone);
        $row = self::row($section);
        $column = self::column($row);

        return ['section' => $section, 'row' => $row, 'column' => $column];
    }

    /**
     * Build a complete element tree: Section > Row > Column > ContentElement.
     *
     * @return array{section: Section, row: Row, column: Column, content: ContentElement}
     */
    public static function treeFor(SiteTree $page, string $zone = 'main'): array
    {
        $tree = self::containerTree($page, $zone);

        return [...$tree, 'content' => self::contentElement($tree['column'])];
    }

    public static function contentElement(Column $column, int $sort = 0, string $title = ''): ContentElement
    {
        $element = ContentElement::create();
        $element->Title = $title;
        $element->Sort = $sort;
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        return $element;
    }
}
