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
use WeDevelop\Grid\Service\GridTreeService;
use WeDevelop\Grid\Service\SharedBlockUsageResolver;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\VetoBlockViewExtension;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\SharedBlockStatus;

#[CoversClass(GridTreeService::class)]
#[CoversClass(SharedBlockUsageResolver::class)]
final class SharedBlockTreeTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private GridTreeService $treeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableAutoScaffolding();

        Versioned::set_stage(Versioned::DRAFT);
        $this->logInWithPermission('ADMIN');

        $this->treeService = Injector::inst()->get(GridTreeService::class);
    }

    /** A block rooted at a Section > Row > Column > ContentElement chain. */
    private function populatedBlock(string $title = 'Shared block', string $leafTitle = 'Shared leaf'): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock($title);
        $section = GridTreeFactory::section($block, zone: '', title: 'Shared section');
        $column = GridTreeFactory::column(GridTreeFactory::row($section));
        GridTreeFactory::contentElement($column, title: $leafTitle);

        return $block;
    }

    public function testReferenceNodeExpandsBlockSubtree(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block);

        $tree = $this->treeService->buildViewableTree($page, 'main');

        self::assertCount(1, $tree->nodes);
        $node = $tree->nodes[0];

        self::assertNotNull($node->sharedBlock);
        self::assertSame((int) $block->ID, $node->sharedBlock->blockId);
        self::assertSame('Shared block', $node->sharedBlock->title);

        self::assertNotNull($node->children);
        self::assertCount(1, $node->children, 'a reference has exactly one child: the block root');

        $root = $node->children[0];
        self::assertSame(ContainerType::Section, $root->containerType);
        self::assertSame('Shared section', $root->title);

        // ... and the rest of the subtree hangs off it as normal typed nodes.
        $row = $root->children[0] ?? null;
        self::assertInstanceOf(GridNode::class, $row);
        self::assertSame(ContainerType::Row, $row->containerType);

        $column = $row->children[0] ?? null;
        self::assertInstanceOf(GridNode::class, $column);
        self::assertSame(ContainerType::Column, $column->containerType);

        $leaf = $column->children[0] ?? null;
        self::assertInstanceOf(GridNode::class, $leaf);
        self::assertSame('Shared leaf', $leaf->title);
    }

    public function testReferenceSelfIsElementTypedNodeRef(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $reference = GridTreeFactory::reference($page, $this->populatedBlock());

        $node = $this->treeService->buildViewableTree($page, 'main')->nodes[0];

        self::assertSame(NodeType::Element, $node->self->type);
        self::assertSame((int) $reference->ID, $node->self->id);
        self::assertNull($node->containerType, 'a reference is not a container');
    }

    public function testRootReferencesAreZoneFiltered(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::reference($page, $this->populatedBlock(), zone: 'sidebar');

        self::assertCount(0, $this->treeService->buildViewableTree($page, 'main')->nodes);
        self::assertCount(1, $this->treeService->buildViewableTree($page, 'sidebar')->nodes);
    }

    public function testReferencesInterleaveWithSectionsBySort(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        GridTreeFactory::section($page, zone: 'main', sort: 1, title: 'First');
        GridTreeFactory::reference($page, $this->populatedBlock(), zone: 'main', sort: 2, title: 'Shared block');
        GridTreeFactory::section($page, zone: 'main', sort: 3, title: 'Third');

        $titles = array_map(
            static fn (GridNode $node): string => $node->title,
            $this->treeService->buildViewableTree($page, 'main')->nodes,
        );

        self::assertSame(['First', 'Shared block', 'Third'], $titles);
    }

    public function testReferenceInsideColumnExpands(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column] = GridTreeFactory::containerTree($page);

        $block = GridTreeFactory::sharedBlock('Leaf block');
        $leaf = ContentElement::create();
        $leaf->Title = 'Shared paragraph';
        $leaf->ParentID = $block->ID;
        $leaf->ParentClass = SharedBlock::class;
        $leaf->write();

        GridTreeFactory::reference($column, $block, zone: '');

        $tree = $this->treeService->buildViewableTree($page, 'main');
        $columnNode = $tree->nodes[0]->children[0]->children[0];

        self::assertCount(1, $columnNode->children);
        $referenceNode = $columnNode->children[0];

        self::assertNotNull($referenceNode->sharedBlock);
        self::assertCount(1, $referenceNode->children);
        self::assertSame('Shared paragraph', $referenceNode->children[0]->title);
    }

    public function testBlockStatusNotPublishedForUnpublishedBlock(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::reference($page, $this->populatedBlock());

        $node = $this->treeService->buildViewableTree($page, 'main')->nodes[0];

        self::assertSame(SharedBlockStatus::NotPublished, $node->sharedBlock?->status);
    }

    public function testBlockStatusPublishedWhenInSync(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block);

        $block->publishRecursive();

        $node = $this->treeService->buildViewableTree($page, 'main')->nodes[0];

        self::assertSame(SharedBlockStatus::Published, $node->sharedBlock?->status);
    }

    public function testBlockStatusModifiedWhenSubtreeElementEdited(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block);
        $block->publishRecursive();

        // Edit an element deep inside the block, not the block record itself.
        $leaf = ContentElement::get()->filter(['Title' => 'Shared leaf'])->first();
        self::assertInstanceOf(ContentElement::class, $leaf);
        $leaf->Title = 'Shared leaf (edited)';
        $leaf->write();

        $node = $this->treeService->buildViewableTree($page, 'main')->nodes[0];

        self::assertSame(SharedBlockStatus::Modified, $node->sharedBlock?->status);
    }

    public function testUsageCountCountsDistinctPages(): void
    {
        $pageA = $this->objFromFixture(Page::class, 'test_page');
        $pageB = $this->objFromFixture(Page::class, 'test_page_2');
        $block = $this->populatedBlock();

        // Two placements on page A (different zones) plus one on page B.
        GridTreeFactory::reference($pageA, $block, zone: 'main');
        GridTreeFactory::reference($pageA, $block, zone: 'sidebar');
        GridTreeFactory::reference($pageB, $block, zone: 'main');

        $node = $this->treeService->buildViewableTree($pageA, 'main')->nodes[0];

        self::assertSame(2, $node->sharedBlock?->usageCount, 'three placements across two pages');
    }

    public function testEmptiedBlockYieldsEmptyChildren(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block);

        $root = $block->getRootElement();
        self::assertNotNull($root);
        $root->delete();

        $node = $this->treeService->buildViewableTree($page, 'main')->nodes[0];

        self::assertNotNull($node->sharedBlock);
        self::assertSame([], $node->children, 'an emptied block renders nothing, and never throws');
        self::assertSame(SharedBlockStatus::NotPublished, $node->sharedBlock->status);
    }

    public function testAnUnviewableBlockLeaksNoMetadataIntoThePageTree(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock('Confidential campaign');
        GridTreeFactory::reference($page, $block);

        SharedBlock::add_extension(VetoBlockViewExtension::class);

        $node = $this->treeService->buildViewableTree($page, 'main')->nodes[0];

        // SharedBlockMeta carries the block's title, its usage count and its
        // CMS edit link. The block's ELEMENTS are already filtered by
        // canView(), so serialising the meta anyway would walk straight past a
        // project's updateCanView veto — the same threat apiPlace() re-checks
        // canView() for.
        self::assertNull($node->sharedBlock);
        self::assertNull($node->children);
    }

    public function testBlockRootedTree(): void
    {
        $block = $this->populatedBlock();

        $tree = $this->treeService->buildViewableTree($block, '');

        self::assertSame(NodeType::SharedBlock, $tree->rootParent->type);
        self::assertSame((int) $block->ID, $tree->rootParent->id);
        self::assertCount(1, $tree->nodes);
        self::assertSame('Shared section', $tree->nodes[0]->title);
    }

    public function testWireShapeOfReferenceNode(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block);

        $encoded = json_encode($this->treeService->buildViewableTree($page, 'main'));
        self::assertIsString($encoded);

        /** @var array{nodes: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($encoded, true);
        $node = $decoded['nodes'][0];

        self::assertArrayHasKey('sharedBlock', $node);
        self::assertSame(
            [
                'blockId' => (int) $block->ID,
                'title' => 'Shared block',
                'usageCount' => 1,
                'status' => 'notPublished',
            ],
            $node['sharedBlock'],
        );
        self::assertArrayHasKey('children', $node);
        self::assertArrayNotHasKey('containerType', $node, 'a reference is discriminated by sharedBlock, not containerType');
    }

    public function testMultiplePlacementsShareIdenticalStructure(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();

        GridTreeFactory::reference($page, $block, zone: 'main', sort: 1);
        GridTreeFactory::reference($page, $block, zone: 'main', sort: 2);

        $nodes = $this->treeService->buildViewableTree($page, 'main')->nodes;
        self::assertCount(2, $nodes);

        // Each placement parents the block root to its OWN reference node, so
        // the roots differ in `parent` while everything below them is shared.
        $firstRoot = $nodes[0]->children[0] ?? null;
        $secondRoot = $nodes[1]->children[0] ?? null;
        self::assertInstanceOf(GridNode::class, $firstRoot);
        self::assertInstanceOf(GridNode::class, $secondRoot);

        self::assertSame($firstRoot->self->id, $secondRoot->self->id, 'one block, one root element');
        self::assertSame($nodes[0]->self->id, $firstRoot->parent->id);
        self::assertSame($nodes[1]->self->id, $secondRoot->parent->id);
        self::assertSame(json_encode($firstRoot->children), json_encode($secondRoot->children));
    }

    public function testFindDescendantsForPageIncludesReferences(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $reference = GridTreeFactory::reference($page, $this->populatedBlock());

        $ids = array_map(
            static fn (object $element): int => (int) $element->ID,
            $this->treeService->findDescendantsForPage($page, 'main'),
        );

        self::assertContains((int) $reference->ID, $ids);
    }

    public function testPageTreeDoesNotLeakOtherBlocks(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $placed = $this->populatedBlock('Placed', 'Placed leaf');
        $this->populatedBlock('Unplaced', 'Unplaced leaf');

        GridTreeFactory::reference($page, $placed);

        $encoded = json_encode($this->treeService->buildViewableTree($page, 'main'));
        self::assertIsString($encoded);

        self::assertStringContainsString('Placed leaf', $encoded);
        self::assertStringNotContainsString('Unplaced leaf', $encoded);
    }
}
