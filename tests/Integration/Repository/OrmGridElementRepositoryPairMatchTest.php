<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Repository;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Repository\OrmGridElementRepository;

#[CoversClass(OrmGridElementRepository::class)]
final class OrmGridElementRepositoryPairMatchTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testFindByParentsOnlyReturnsElementsMatchingTheRequestedClassIdPairs(): void
    {
        // Build a real Section→Row→Column tree under a single page so we have
        // a GridElement at every ParentClass level. IDs in a fresh test DB:
        //   pageA->ID   = 1
        //   section->ID = 1 (first GridElement)
        //   row->ID     = 2 (auto-scaffolded)
        //   column->ID  = 3 (auto-scaffolded)
        $pageA = Page::create(['Title' => 'A']);
        $pageA->write();

        $section = Section::create();
        $section->ParentID = $pageA->ID;
        $section->ParentClass = Page::class;
        $section->write();

        $row = $section->getChildren()->first();
        self::assertInstanceOf(Row::class, $row);
        $column = $row->getChildren()->first();
        self::assertInstanceOf(Column::class, $column);

        $repo = new OrmGridElementRepository();

        // Query with *wrong* class/id pairs whose IDs collide with real records:
        //   (Page, $row->ID)    — $row->ID == 2, no Page with id 2 has a child.
        //   (Row,      $section->ID) — $section->ID == 1, no Row with id 1 exists
        //                              ($row->ID == 2), so no GridElement has
        //                              (ParentClass=Row, ParentID=1).
        //
        // The flattened-IN implementation would produce
        //   ParentID IN (2, 1) AND ParentClass IN (Page, Row)
        // which matches the real section (Page, 1) and the real column (Row, 2)
        // — leaking records whose pairs were never requested. Pair-matched logic
        // must return zero.
        $result = $repo->findByParents([
            Page::class => [(int) $row->ID],
            Row::class => [(int) $section->ID],
        ]);

        self::assertCount(
            0,
            $result,
            'findByParents must pair-match (ParentClass, ParentID), not return the cartesian product',
        );
    }

    public function testFindByParentsReturnsRealPairsAcrossClasses(): void
    {
        $page = Page::create(['Title' => 'P']);
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = Page::class;
        $section->write();

        $repo = new OrmGridElementRepository();
        $result = $repo->findByParents([
            Page::class => [(int) $page->ID],
            Section::class => [(int) $section->ID],
        ]);

        // Expect at least the section (under page) and the auto-scaffolded row (under section).
        self::assertGreaterThanOrEqual(2, count($result));
    }
}
