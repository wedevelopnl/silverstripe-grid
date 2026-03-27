<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(Row::class)]
final class RowTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    // ── Auto-scaffolding ────────────────────────────────────────

    public function testAutoScaffoldCreatesColumn(): void
    {
        // Suppress Section scaffold so we control the tree, but leave Row scaffold on
        Config::modify()->set(Section::class, 'auto_scaffold', false);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $columns = $row->Columns();
        self::assertCount(1, $columns);
        self::assertInstanceOf(Column::class, $columns->first());
    }

    public function testAutoScaffoldIdempotent(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', false);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        self::assertCount(1, $row->Columns());

        $row->Title = 'Updated';
        $row->write();

        self::assertCount(1, $row->Columns());
    }

    public function testAutoScaffoldDisabledViaConfig(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        self::assertCount(0, $row->Columns());
    }

    public function testAutoScaffoldSkippedOnLiveStage(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', false);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        Versioned::set_stage(Versioned::LIVE);

        $row = Row::create();
        $row->ParentID = $section->ID;
        $row->ParentClass = $section::class;
        $row->write();

        self::assertCount(0, $row->Columns());
    }

    // ── Container behavior ──────────────────────────────────────

    public function testGetChildrenReturnsColumns(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $children = $row->getChildren();
        self::assertCount(1, $children);
        self::assertSame((int) $column->ID, (int) $children->first()->ID);
    }

    public function testGetContainerType(): void
    {
        self::assertSame(ContainerType::Row, Row::singleton()->getContainerType());
    }

    // ── Row classes ─────────────────────────────────────────────

    public function testGetRowClassesReturnsNonEmptyString(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $classes = $row->getRowClasses();

        self::assertNotEmpty($classes);
    }
}
