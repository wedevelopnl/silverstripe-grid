<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\LocationTrailBuilder;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\TrailSegment;

#[CoversClass(LocationTrailBuilder::class)]
#[CoversClass(TrailSegment::class)]
final class LocationTrailBuilderTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private LocationTrailBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->builder = Injector::inst()->get(LocationTrailBuilder::class);
    }

    /**
     * @param list<GridElement> $elements
     * @return list<int>
     */
    private static function ids(array $elements): array
    {
        return array_map(static fn (GridElement $e): int => (int) $e->ID, $elements);
    }

    // ── ancestors() ─────────────────────────────────────────────

    public function testAncestorsReturnsContainerChainOutermostFirst(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column);

        $index = $this->builder->index(GridElement::get());

        // Outermost first: Section → Row → Column. The element's own level is excluded.
        self::assertSame(
            [(int) $section->ID, (int) $row->ID, (int) $column->ID],
            self::ids($this->builder->ancestors($content, $index)),
        );
    }

    public function testAncestorsOfSectionIsEmpty(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $index = $this->builder->index(GridElement::get());

        // A Section sits directly under the page, so it has no element ancestors.
        self::assertSame([], $this->builder->ancestors($section, $index));
    }

    public function testAncestorsIgnoreParentIdCollisionAcrossClasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        // A content element whose ID we will collide against.
        $host = GridTreeFactory::section($page);
        $hostRow = GridTreeFactory::row($host);
        $hostColumn = GridTreeFactory::column($hostRow);
        $content = GridTreeFactory::contentElement($hostColumn);
        $collidingId = (int) $content->ID;

        // A page-parented Section whose ParentID is forced to collide with the
        // content element's ID (ParentClass stays the page class).
        $section = GridTreeFactory::section($page);
        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            'UPDATE "%s" SET "ParentID" = %d WHERE "ID" = %d',
            $table,
            $collidingId,
            (int) $section->ID,
        ));
        $section = GridElement::get()->byID((int) $section->ID);
        self::assertInstanceOf(Section::class, $section);

        $index = $this->builder->index(GridElement::get());

        // The section's parent key is "<PageClass>:<collidingId>", which is not a
        // GridElement key — so the same-numbered content element is NOT a false
        // ancestor. Keying the index by bare ID instead of "Class:ID" would make
        // this return [content] and fail.
        self::assertSame([], $this->builder->ancestors($section, $index));
    }

    // ── trail() ─────────────────────────────────────────────────

    public function testTrailRootsAtPageFollowedByAncestors(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, 'main', 0, 'Hero Section');
        $row = GridTreeFactory::row($section, 0, 'Top Row');
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column, 0, 'Intro Text');

        $index = $this->builder->index(GridElement::get());
        $segments = $this->builder->trail($content, $page, $index);

        // Page root, then each ancestor outermost-first; the element itself omitted.
        self::assertSame(
            [(string) $page->Title, 'Hero Section', 'Top Row', (string) $column->Title],
            array_map(static fn (TrailSegment $s): string => $s->label, $segments),
        );

        // Every segment links to its own subject's CMS edit screen.
        self::assertSame(
            [
                $page->getCMSEditLink(),
                $section->getCMSEditLink(),
                $row->getCMSEditLink(),
                $column->getCMSEditLink(),
            ],
            array_map(static fn (TrailSegment $s): ?string => $s->editLink, $segments),
        );
    }

    public function testTrailLabelFallsBackToTypeWhenTitleBlank(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, 'main', 0, 'Hero Section');
        $row = GridTreeFactory::row($section, 0, 'Top Row');
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column, 0, 'Intro Text');

        // Blank the Row's stored title directly (bypassing the write-time default).
        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            'UPDATE "%s" SET "Title" = \'\' WHERE "ID" = %d',
            $table,
            (int) $row->ID,
        ));

        $index = $this->builder->index(GridElement::get());
        $segments = $this->builder->trail($content, $page, $index);

        // Row segment (index 2) falls back to the element type instead of an empty label.
        self::assertSame($row->getType(), $segments[2]->label);
    }
}
