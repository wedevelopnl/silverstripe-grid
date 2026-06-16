<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\KeysForCache;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use Terraformers\KeysForCache\Services\ProcessedUpdatesService;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Guards the keys-for-cache integration: cache-key presence on grid elements
 * and transitive upward invalidation through the downward `cares` graph.
 */
#[CoversNothing]
final class GridCacheKeyTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    private function createPage(string $segment): Page
    {
        $page = Page::create();
        $page->Title = 'KFC Test';
        $page->URLSegment = $segment;
        $page->writeToStage(Versioned::DRAFT);

        return $page;
    }

    /** Build Section→Row→Column (auto-scaffolded) and return the leaf Column. */
    private function createGrid(Page $page): Column
    {
        $section = Section::create();
        $section->Title = 'Section';
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        /** @var Row $row */
        $row = $section->getChildren()->first();
        /** @var Column $column */
        $column = $row->getChildren()->first();

        return $column;
    }

    /** Re-fetch a record from the DB so cached in-memory state is dropped. */
    private function fresh(string $class, int $id): object
    {
        return $class::get()->byID($id);
    }

    public function testRelationshipGraphBuildsWithoutError(): void
    {
        // KFC builds its relationship graph lazily on the first write of a
        // has_cache_key object, throwing GraphBuildException if any `cares`
        // relation name in our config is unresolvable. Writing a Section
        // (and its auto-scaffolded children) exercises that path.
        $page = $this->createPage('kfc-graph');
        $this->createGrid($page);
        self::assertTrue(true, 'No GraphBuildException thrown while building the grid');
    }

    public function testGridElementsExposeCacheKey(): void
    {
        $page = $this->createPage('kfc-keys');
        $column = $this->createGrid($page);
        $row = $column->Parent();
        $section = $row->Parent();

        self::assertNotNull($section->getCacheKey());
        self::assertNotSame('', $section->getCacheKey());
        self::assertNotNull($row->getCacheKey());
        self::assertNotSame('', $row->getCacheKey());
        self::assertNotNull($column->getCacheKey());
        self::assertNotSame('', $column->getCacheKey());
    }

    public function testDescendantChangeInvalidatesAncestorKeys(): void
    {
        $page = $this->createPage('kfc-transitive');
        $column = $this->createGrid($page);
        $row = $column->Parent();
        $section = $row->Parent();

        $sectionBefore = $section->getCacheKey();
        $rowBefore = $row->getCacheKey();
        $columnBefore = $column->getCacheKey();

        // KFC only updates a record's cache key once per "request". A PHPUnit
        // test case is one request, so the scaffolding writes above already
        // processed the ancestor keys. Flush KFC's processed-update cache so
        // the descendant write below actually invalidates them — this is the
        // mechanism KFC's own unit-testing docs prescribe.
        ProcessedUpdatesService::singleton()->flush();

        // Add a leaf content element under the column and write it.
        $element = ContentElement::create();
        $element->Title = 'Leaf';
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        $sectionAfter = $this->fresh(Section::class, $section->ID)->getCacheKey();
        $rowAfter = $this->fresh(Row::class, $row->ID)->getCacheKey();
        $columnAfter = $this->fresh(Column::class, $column->ID)->getCacheKey();

        self::assertNotSame($columnBefore, $columnAfter, 'Column key must change');
        self::assertNotSame($rowBefore, $rowAfter, 'Row key must change (transitive)');
        self::assertNotSame($sectionBefore, $sectionAfter, 'Section key must change (transitive)');
    }

    public function testUnrelatedTreeKeyIsUnaffected(): void
    {
        $pageA = $this->createPage('kfc-iso-a');
        $columnA = $this->createGrid($pageA);

        $pageB = $this->createPage('kfc-iso-b');
        $columnB = $this->createGrid($pageB);
        $sectionB = $columnB->Parent()->Parent();
        $sectionBBefore = $sectionB->getCacheKey();

        // Flush KFC's per-request processed-update cache so the write below
        // genuinely fires cache invalidation (see the transitive test). Without
        // this the unrelated key would be unchanged for the wrong reason.
        ProcessedUpdatesService::singleton()->flush();

        // Change tree A.
        $element = ContentElement::create();
        $element->Title = 'A leaf';
        $element->ParentID = $columnA->ID;
        $element->ParentClass = $columnA::class;
        $element->write();

        $sectionBAfter = $this->fresh(Section::class, $sectionB->ID)->getCacheKey();
        self::assertSame($sectionBBefore, $sectionBAfter, 'Unrelated tree key must not change');
    }
}
