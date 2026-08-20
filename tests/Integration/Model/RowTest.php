<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(Row::class)]
final class RowTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testAutoScaffoldCreatesColumn(): void
    {
        // Section scaffold stays suppressed so we control the tree; Row scaffold is the subject
        Config::modify()->set(Row::class, 'auto_scaffold', true);

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $columns = $row->Columns();
        self::assertCount(1, $columns);
        self::assertInstanceOf(Column::class, $columns->first());
    }

    public function testAutoScaffoldIdempotent(): void
    {
        Config::modify()->set(Row::class, 'auto_scaffold', true);

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        self::assertCount(1, $row->Columns());

        $row->Title = 'Updated';
        $row->write();

        self::assertCount(1, $row->Columns());
    }

    public function testAutoScaffoldDisabledViaConfig(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        self::assertCount(0, $row->Columns());
    }

    public function testAutoScaffoldSkippedOnLiveStage(): void
    {
        // Row scaffolding stays ON so the LIVE-stage guard is what prevents it
        Config::modify()->set(Row::class, 'auto_scaffold', true);

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        Versioned::set_stage(Versioned::LIVE);

        $row = Row::create();
        $row->ParentID = $section->ID;
        $row->ParentClass = $section::class;
        $row->write();

        self::assertCount(0, $row->Columns());
    }

    public function testGetChildrenReturnsColumns(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['row' => $row, 'column' => $column] = GridTreeFactory::containerTree($page);

        $children = $row->getChildren();
        self::assertCount(1, $children);
        self::assertSame((int) $column->ID, (int) $children->first()->ID);
    }

    public function testGetContainerType(): void
    {
        self::assertSame(ContainerType::Row, Row::singleton()->getContainerType());
    }

    public function testGetRowClassesReturnsNonEmptyString(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $classes = $row->getRowClasses();

        self::assertNotEmpty($classes);
    }
}
