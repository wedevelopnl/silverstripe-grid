<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Verifies that the auto-scaffolding cascade (Section → Row → Column via
 * onAfterWrite) produces a complete, queryable hierarchy through the
 * ContainerInterface when Fluent locale filtering is active.
 */
final class FluentAutoScaffoldingTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);
    }

    /**
     * Writing a Section with auto-scaffold enabled must produce a complete
     * Section → Row → Column hierarchy that is fully queryable through
     * getChildren() and getContainerType() in the active locale.
     */
    public function testCascadeProducesQueryableHierarchy(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        $section = Section::create();
        $section->Title = 'Scaffolded Section';
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Section has children queryable via ContainerInterface
        self::assertTrue($section->hasChildren());
        self::assertSame(ContainerType::Section, $section->getContainerType());

        $rows = $section->getChildren();
        self::assertCount(1, $rows);

        /** @var Row $row */
        $row = $rows->first();
        self::assertInstanceOf(Row::class, $row);
        self::assertTrue($row->hasChildren());
        self::assertSame(ContainerType::Row, $row->getContainerType());

        $columns = $row->getChildren();
        self::assertCount(1, $columns);

        /** @var Column $column */
        $column = $columns->first();
        self::assertInstanceOf(Column::class, $column);
        self::assertSame(ContainerType::Column, $column->getContainerType());
        self::assertFalse($column->hasChildren(), 'Column should have no children after scaffolding');
    }

    /**
     * A hierarchy scaffolded in one locale must not be queryable through
     * ContainerInterface methods when a different locale is active.
     */
    public function testCascadeInOneLocaleDoesNotLeakToAnother(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Scaffold in English
        $section = Section::create();
        $section->Title = 'English Only';
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Confirm English hierarchy exists
        self::assertTrue($section->hasChildren());

        // Switch to Dutch — query the hierarchy from scratch
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        // No sections exist for this page in Dutch
        $sections = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => $page::class,
        ]);
        self::assertCount(0, $sections);

        // No rows or columns exist in Dutch at all
        self::assertCount(0, Row::get());
        self::assertCount(0, Column::get());
    }
}
