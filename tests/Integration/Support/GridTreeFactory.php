<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\CMS\Model\SiteTree;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
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
    public static function section(SiteTree $page, string $zone = 'main', int $sort = 0, string $title = ''): Section
    {
        $section = Section::create();
        $section->Title = $title;
        $section->Zone = $zone;
        $section->Sort = $sort;
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        return $section;
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

    public static function column(Row $row, int $sort = 0, ?GridSettings $gridSettings = null): Column
    {
        $column = Column::create();
        $column->Sort = $sort;
        $column->ParentID = $row->ID;
        $column->ParentClass = $row::class;

        if ($gridSettings !== null) {
            $column->setGridSettings($gridSettings);
        }

        $column->write();

        return $column;
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
