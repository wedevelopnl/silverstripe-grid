<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Service\SharedBlockService;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\ReorderValidator;

#[CoversClass(SharedBlockService::class)]
final class SharedBlockServiceTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private SharedBlockService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableAutoScaffolding();

        Versioned::set_stage(Versioned::DRAFT);
        $this->logInWithPermission('ADMIN');

        $this->service = Injector::inst()->get(SharedBlockService::class);
    }

    private function sectionRootedBlock(string $title = 'Shared block'): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock($title);
        $section = GridTreeFactory::section($block, zone: '', title: 'Shared section');
        GridTreeFactory::contentElement(
            GridTreeFactory::column(GridTreeFactory::row($section)),
            title: 'Shared leaf',
        );

        return $block;
    }

    private function leafRootedBlock(): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock('Leaf block');

        $leaf = ContentElement::create();
        $leaf->Title = 'Shared paragraph';
        $leaf->ParentID = $block->ID;
        $leaf->ParentClass = SharedBlock::class;
        $leaf->write();

        return $block;
    }

    public function testPlaceCreatesReferenceAtPageRootWithZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page, zone: 'main', sort: 1);
        $block = $this->sectionRootedBlock();

        $result = $this->service->place($block, $page, 'main', null);

        self::assertTrue($result->isOk());
        $reference = $result->unwrap();

        self::assertSame((int) $block->ID, (int) $reference->BlockID);
        self::assertSame((int) $page->ID, (int) $reference->ParentID);
        self::assertSame($page::class, $reference->ParentClass);
        self::assertSame('main', $reference->Zone);
        self::assertSame(2, (int) $reference->Sort, 'appended after the existing section');
    }

    public function testPlaceIntoColumnLeavesZoneEmpty(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column] = GridTreeFactory::containerTree($page);

        $result = $this->service->place($this->leafRootedBlock(), $column, 'main', null);

        self::assertTrue($result->isOk());
        self::assertSame('', (string) $result->unwrap()->Zone);
        self::assertSame(Column::class, $result->unwrap()->ParentClass);
    }

    public function testPlaceRejectsIncompatibleRoot(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        // A leaf-rooted block cannot sit at page level.
        $result = $this->service->place($this->leafRootedBlock(), $page, 'main', null);

        self::assertTrue($result->isErr());
        // The key identifies WHICH check refused it. place() validates before
        // writing, and it can only do that while the reference is still
        // unparented — assigning the parent first makes the validator's
        // same-parent guard short-circuit to ok and the pre-write check a
        // no-op, leaving the write to catch it one layer later.
        self::assertSame(
            ReorderValidator::class . '.PAGE_LEVEL_REJECTED',
            $result->errors()[0]->key,
            'the pre-write placement check must be the one that refuses this',
        );
        self::assertSame(
            0,
            SharedBlockReference::get()->count(),
            'a rejected placement must not leave a row behind',
        );
    }

    public function testPlaceRejectsAnEmptyBlock(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $empty = GridTreeFactory::sharedBlock('Empty block');

        $result = $this->service->place($empty, $page, 'main', null);

        self::assertTrue($result->isErr());
        self::assertSame(ReorderValidator::class . '.BLOCK_EMPTY', $result->errors()[0]->key);
        self::assertSame(0, SharedBlockReference::get()->count());
    }

    public function testPlaceInsertAfterPositionsReference(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $first = GridTreeFactory::section($page, zone: 'main', sort: 1, title: 'First');
        $second = GridTreeFactory::section($page, zone: 'main', sort: 2, title: 'Second');

        $result = $this->service->place($this->sectionRootedBlock(), $page, 'main', (int) $first->ID);

        self::assertTrue($result->isOk());
        $reference = $result->unwrap();

        self::assertSame(
            [(int) $first->ID, (int) $reference->ID, (int) $second->ID],
            $this->rootOrder($page, 'main'),
        );
    }

    /**
     * Root-level element IDs for a page + zone, in Sort order across both root
     * tables — the same interleave the tree read performs.
     *
     * @return list<int>
     */
    private function rootOrder(Page $page, string $zone): array
    {
        $roots = [];

        foreach (Section::get()->filter(['ParentID' => $page->ID, 'ParentClass' => $page::class, 'Zone' => $zone]) as $s) {
            $roots[] = [(int) $s->Sort, (int) $s->ID];
        }

        foreach (SharedBlockReference::get()->filter(['ParentID' => $page->ID, 'ParentClass' => $page::class, 'Zone' => $zone]) as $r) {
            $roots[] = [(int) $r->Sort, (int) $r->ID];
        }

        usort($roots, static fn (array $a, array $b): int => $a <=> $b);

        return array_map(static fn (array $pair): int => $pair[1], $roots);
    }

    public function testConvertMovesSubtreeAndLeavesReference(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main', sort: 2, title: 'Promote me');
        $row = GridTreeFactory::row($section);
        $sectionId = (int) $section->ID;
        $rowId = (int) $row->ID;

        $result = $this->service->convertToShared($section, 'Promoted block');

        self::assertTrue($result->isOk());
        $block = $result->unwrap();

        // Identity preserved: the same Section row is now the block's root.
        $root = $block->getRootElement();
        self::assertNotNull($root);
        self::assertSame($sectionId, (int) $root->ID);
        self::assertSame(SharedBlock::class, $root->ParentClass);
        self::assertSame('', (string) $root->Zone, 'Zone is placement data and moves to the reference');

        // Descendants follow their unchanged parent.
        $reloadedRow = Row::get()->byID($rowId);
        self::assertInstanceOf(Row::class, $reloadedRow);
        self::assertSame($sectionId, (int) $reloadedRow->ParentID);

        // The vacated position now holds the reference.
        $reference = SharedBlockReference::get()->filter(['BlockID' => $block->ID])->first();
        self::assertInstanceOf(SharedBlockReference::class, $reference);
        self::assertSame(2, (int) $reference->Sort);
        self::assertSame('main', (string) $reference->Zone);
        self::assertSame((int) $page->ID, (int) $reference->ParentID);
    }

    public function testConvertRejectsReference(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $reference = GridTreeFactory::reference($page, $this->sectionRootedBlock());

        $result = $this->service->convertToShared($reference, 'Nope');

        self::assertTrue($result->isErr());
        self::assertSame(SharedBlockService::class . '.ALREADY_SHARED', $result->errors()[0]->key);
    }

    public function testConvertRejectsElementInsideSharedBlock(): void
    {
        $block = $this->sectionRootedBlock();
        $root = $block->getRootElement();
        self::assertInstanceOf(Section::class, $root);
        $row = $root->Rows()->first();
        self::assertInstanceOf(Row::class, $row);

        $result = $this->service->convertToShared($row, 'Nope');

        self::assertTrue($result->isErr());
        self::assertSame(SharedBlockService::class . '.SHARED_NESTING', $result->errors()[0]->key);
    }

    public function testConvertRejectsUnplacedElement(): void
    {
        $orphan = Section::create();
        $orphan->Title = 'Orphan';
        $orphan->write();

        $result = $this->service->convertToShared($orphan, 'Nope');

        self::assertTrue($result->isErr());
        self::assertSame(SharedBlockService::class . '.NOT_PLACED', $result->errors()[0]->key);
    }

    public function testConvertLeavesTheOldSubtreeOnLiveUntilThePageIsRepublished(): void
    {
        // Converting writes to DRAFT only, so live keeps rendering the
        // pre-conversion subtree under the page.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main', title: 'Live section');
        $sectionId = (int) $section->ID;

        $page->publishRecursive();
        self::assertTrue($this->service->convertToShared($section, 'Promoted')->isOk());

        Versioned::set_stage(Versioned::LIVE);
        $liveSection = Section::get()->byID($sectionId);

        self::assertInstanceOf(Section::class, $liveSection);
        self::assertSame($page::class, $liveSection->ParentClass);
    }

    public function testRepublishingThePageUnlinksTheDisownedSubtreeFromLive(): void
    {
        // The live-state window. Republishing the page runs SilverStripe's
        // disowned-object cleanup, which clears the LIVE parent of the subtree
        // that moved into the block — so until the block itself is published,
        // the placement renders nothing on live. That is exactly what the
        // editor's notPublished badge warns about.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main', title: 'Live section');
        $sectionId = (int) $section->ID;

        $page->publishRecursive();
        $block = $this->service->convertToShared($section, 'Promoted')->unwrap();
        $page->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);
        $liveSection = Section::get()->byID($sectionId);
        self::assertInstanceOf(Section::class, $liveSection);
        self::assertNotSame(
            $page::class,
            $liveSection->ParentClass,
            'the disowned section no longer hangs off the live page',
        );
        self::assertSame(1, SharedBlockReference::get()->count(), 'the placement published with the page');

        // Publishing the block closes the window: the subtree is live again,
        // now owned by the block and reached through the placement.
        Versioned::set_stage(Versioned::DRAFT);
        self::assertTrue($this->service->setPublished($block, true)->isOk());

        Versioned::set_stage(Versioned::LIVE);
        $afterPublish = Section::get()->byID($sectionId);
        self::assertInstanceOf(Section::class, $afterPublish);
        self::assertSame(SharedBlock::class, $afterPublish->ParentClass);
    }

    public function testPagePublishCarriesTheReferenceButNotTheBlock(): void
    {
        // The ownership boundary in one test: the placement is page content and
        // publishes with it; the block behind it keeps its own lifecycle.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->sectionRootedBlock();
        GridTreeFactory::reference($page, $block);

        $page->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);
        self::assertSame(1, SharedBlockReference::get()->count(), 'the placement published with the page');
        self::assertNull(SharedBlock::get()->byID($block->ID), 'page publish must not reach the shared source');
        self::assertSame(
            0,
            Section::get()->filter(['ParentClass' => SharedBlock::class])->count(),
            'nor the block subtree',
        );
    }

    public function testDetachCreatesIndependentCopy(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->sectionRootedBlock();
        $reference = GridTreeFactory::reference($page, $block, zone: 'main', sort: 3);
        $referenceId = (int) $reference->ID;
        $originalRootId = (int) $block->getRootElement()?->ID;

        $result = $this->service->detach($reference);

        self::assertTrue($result->isOk());
        $copy = $result->unwrap();

        self::assertNotSame($originalRootId, (int) $copy->ID, 'the copy is a new row');
        self::assertSame((int) $page->ID, (int) $copy->ParentID);
        self::assertSame($page::class, $copy->ParentClass);
        self::assertSame(3, (int) $copy->Sort, 'the copy takes over the placement position');
        self::assertSame('main', (string) $copy->Zone);
        self::assertSame('Shared section', $copy->Title);

        self::assertNull(SharedBlockReference::get()->byID($referenceId), 'the reference is archived');

        // The block and its subtree are untouched.
        self::assertSame($originalRootId, (int) $block->getRootElement()?->ID);
    }

    public function testDetachedCopyIsIndependentOfLaterBlockEdits(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->sectionRootedBlock();
        $reference = GridTreeFactory::reference($page, $block);

        $copy = $this->service->detach($reference)->unwrap();

        $root = $block->getRootElement();
        self::assertNotNull($root);
        $root->Title = 'Renamed in the library';
        $root->write();

        $reloadedCopy = Section::get()->byID($copy->ID);
        self::assertInstanceOf(Section::class, $reloadedCopy);
        self::assertSame('Shared section', $reloadedCopy->Title);
    }

    public function testDetachCopiesTheWholeSubtree(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $reference = GridTreeFactory::reference($page, $this->sectionRootedBlock());

        $copy = $this->service->detach($reference)->unwrap();
        self::assertInstanceOf(Section::class, $copy);

        $copiedRow = $copy->Rows()->first();
        self::assertInstanceOf(Row::class, $copiedRow);

        $copiedColumn = $copiedRow->Columns()->first();
        self::assertInstanceOf(Column::class, $copiedColumn);

        $copiedLeaf = $copiedColumn->getChildren()->first();
        self::assertInstanceOf(ContentElement::class, $copiedLeaf);
        self::assertSame('Shared leaf', $copiedLeaf->Title);
    }

    public function testDetachFailsOnEmptyBlock(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->sectionRootedBlock();
        $reference = GridTreeFactory::reference($page, $block);

        $block->getRootElement()?->delete();

        $result = $this->service->detach($reference);

        self::assertTrue($result->isErr());
        self::assertSame(SharedBlockService::class . '.BLOCK_EMPTY', $result->errors()[0]->key);
    }

    public function testSetPublishedPublishesSubtree(): void
    {
        $block = $this->sectionRootedBlock();

        self::assertTrue($this->service->setPublished($block, true)->isOk());

        Versioned::set_stage(Versioned::LIVE);
        self::assertNotNull(SharedBlock::get()->byID($block->ID));
        self::assertSame(1, Section::get()->filter(['ParentClass' => SharedBlock::class])->count());
        self::assertSame(1, ContentElement::get()->filter(['Title' => 'Shared leaf'])->count());
    }

    public function testUnpublishRemovesSubtreeFromLive(): void
    {
        $block = $this->sectionRootedBlock();
        self::assertTrue($this->service->setPublished($block, true)->isOk());

        self::assertTrue($this->service->setPublished($block, false)->isOk());

        Versioned::set_stage(Versioned::LIVE);
        self::assertNull(SharedBlock::get()->byID($block->ID));
    }
}
