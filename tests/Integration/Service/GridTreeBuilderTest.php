<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Service\GridTreeBuilder;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;

#[CoversClass(GridTreeBuilder::class)]
final class GridTreeBuilderTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/ElementTreeTest.yml';

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        TestPage::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        TestPage::class => [
            GridPageExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    public function onBeforeLoadFixtures(): void
    {
        parent::onBeforeLoadFixtures();
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    /**
     * @return array<int, list<GridNode>>
     */
    private function buildTree(): array
    {
        $page = $this->objFromFixture(TestPage::class, 'testpage');

        /** @var GridTreeBuilder $builder */
        $builder = Injector::inst()->get(GridTreeBuilder::class);

        return $builder->buildForPage($page);
    }

    /**
     * Get the page ID used as the tree root key.
     */
    private function getPageId(): int
    {
        $page = $this->objFromFixture(TestPage::class, 'testpage');

        return (int) $page->ID;
    }

    // ---- Full tree structure ----

    public function testTreeShapeMatchesFixtureHierarchy(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();

        $this->assertArrayHasKey($pageId, $tree);

        // 2 sections at root
        $sections = $tree[$pageId];
        $this->assertCount(2, $sections);
        $this->assertSame('First Section', $sections[0]->title);
        $this->assertSame('Second Section', $sections[1]->title);
        $this->assertSame($this->idFromFixture(Section::class, 'section1'), $sections[0]->id);
        $this->assertSame($this->idFromFixture(Section::class, 'section2'), $sections[1]->id);

        // Section 1 → 3 rows
        $rows = $sections[0]->children;
        $this->assertCount(3, $rows);
        $this->assertSame('First Row', $rows[0]->title);
        $this->assertSame('Second Row', $rows[1]->title);
        $this->assertSame('Empty Row', $rows[2]->title);
        $this->assertSame($this->idFromFixture(Row::class, 'row1'), $rows[0]->id);
        $this->assertSame($this->idFromFixture(Row::class, 'row2'), $rows[1]->id);
        $this->assertSame($this->idFromFixture(Row::class, 'row3'), $rows[2]->id);

        // Row 1 → 2 columns
        $columns = $rows[0]->children;
        $this->assertCount(2, $columns);
        $this->assertSame('Left Column', $columns[0]->title);
        $this->assertSame('Right Column', $columns[1]->title);
        $this->assertSame($this->idFromFixture(Column::class, 'col1'), $columns[0]->id);
        $this->assertSame($this->idFromFixture(Column::class, 'col2'), $columns[1]->id);

        // Column 1 → 2 leaves
        $leaves = $columns[0]->children;
        $this->assertCount(2, $leaves);
        $this->assertSame('Text Block', $leaves[0]->title);
        $this->assertSame('Image Block', $leaves[1]->title);
        $this->assertSame($this->idFromFixture(GridElement::class, 'leaf1'), $leaves[0]->id);
        $this->assertSame($this->idFromFixture(GridElement::class, 'leaf2'), $leaves[1]->id);

        // Column 2 → 1 leaf
        $col2Leaves = $columns[1]->children;
        $this->assertCount(1, $col2Leaves);
        $this->assertSame('Video Block', $col2Leaves[0]->title);
        $this->assertSame($this->idFromFixture(GridElement::class, 'leaf3'), $col2Leaves[0]->id);
    }

    // ---- parentId threading ----

    public function testParentAreaIdMatchesContainingArea(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();

        // Root sections: parentId equals the page ID
        $sections = $tree[$pageId];
        foreach ($sections as $section) {
            $this->assertSame($pageId, $section->parentId, 'Root section parentId should equal page ID');
        }

        // Rows: parentId equals parent section's id
        $section1 = $sections[0];
        foreach ($section1->children as $row) {
            $this->assertSame(
                $section1->id,
                $row->parentId,
                'Row parentId should equal parent section id',
            );

            // Columns: parentId equals parent row's id
            foreach ($row->children ?? [] as $column) {
                $this->assertSame(
                    $row->id,
                    $column->parentId,
                    'Column parentId should equal parent row id',
                );

                // Leaves: parentId equals parent column's id
                foreach ($column->children ?? [] as $leaf) {
                    $this->assertSame(
                        $column->id,
                        $leaf->parentId,
                        'Leaf parentId should equal parent column id',
                    );
                }
            }
        }
    }

    // ---- Sort ordering ----

    public function testSiblingsOrderedBySort(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();

        // Sections ordered by Sort
        $sectionTitles = \array_map(static fn (GridNode $n): string => $n->title, $tree[$pageId]);
        $this->assertSame(['First Section', 'Second Section'], $sectionTitles);

        // Rows in section 1 ordered by Sort
        $rowTitles = \array_map(static fn (GridNode $n): string => $n->title, $tree[$pageId][0]->children);
        $this->assertSame(['First Row', 'Second Row', 'Empty Row'], $rowTitles);

        // Columns in row 1 ordered by Sort
        $columnTitles = \array_map(static fn (GridNode $n): string => $n->title, $tree[$pageId][0]->children[0]->children);
        $this->assertSame(['Left Column', 'Right Column'], $columnTitles);

        // Leaves in column 1 ordered by Sort
        $leafTitles = \array_map(static fn (GridNode $n): string => $n->title, $tree[$pageId][0]->children[0]->children[0]->children);
        $this->assertSame(['Text Block', 'Image Block'], $leafTitles);
    }

    // ---- Container fields ----

    public function testContainerNodeIncludesContainerFields(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $section = $tree[$pageId][0];

        $this->assertNotNull($section->containerType);
        $this->assertSame('section', $section->containerType->value);

        $this->assertNotNull($section->allowedTypes);
        $this->assertArrayHasKey(Row::class, $section->allowedTypes);

        $this->assertNotNull($section->children);
        $this->assertIsArray($section->children);

        // gridSettings is only for Column nodes, null on Section
        $this->assertNull($section->gridSettings);
    }

    public function testLeafNodeExcludesContainerFields(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $leaf = $tree[$pageId][0]->children[0]->children[0]->children[0];

        $this->assertNull($leaf->containerType);
        $this->assertNull($leaf->allowedTypes);
        $this->assertNull($leaf->children);
    }

    // ---- Base fields ----

    public function testBaseFieldsMatchElementData(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $section = $tree[$pageId][0];

        // Verify on a section node for richer coverage (containers have more fields)
        $this->assertSame($this->idFromFixture(Section::class, 'section1'), $section->id);
        $this->assertSame($pageId, $section->parentId);
        $this->assertSame('First Section', $section->title);
        $this->assertIsInt($section->version);
        $this->assertGreaterThan(0, $section->version);
        $this->assertNull($section->obsoleteClassName);

        // Permission booleans must be true for admin user (default test identity)
        $this->assertTrue($section->canDelete);
        $this->assertTrue($section->canPublish);
        $this->assertTrue($section->canUnpublish);
        $this->assertTrue($section->canCreate);

        // editLink contains the page ID so the CMS can route to the editor
        $this->assertNotNull($section->editLink);
        $this->assertStringContainsString((string) $pageId, $section->editLink);

        $this->assertIsArray($section->statusFlags);
    }

    public function testBlockSchemaStructure(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $section = $tree[$pageId][0];

        $schema = $section->blockSchema;

        // All 6 expected keys present
        $expectedKeys = ['typeName', 'type', 'title', 'summary', 'label', 'icon'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $schema, "blockSchema missing key: $key");
        }

        $this->assertIsString($schema['typeName']);
        $this->assertNotEmpty($schema['typeName']);

        $this->assertIsString($schema['type']);

        $this->assertIsString($schema['summary']);

        // label equals the element's getType() value
        $this->assertSame('Section', $schema['label']);

        // icon matches the Section's configured icon (not the fallback)
        $this->assertSame('font-icon-block-layout', $schema['icon']);
    }

    // ---- Empty containers ----

    public function testEmptyContainerHasEmptyChildrenArray(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();

        // Section 2 (no rows)
        $section2 = $tree[$pageId][1];
        $this->assertSame('Second Section', $section2->title);
        $this->assertSame([], $section2->children);

        // Row 3 (no columns)
        $row3 = $tree[$pageId][0]->children[2];
        $this->assertSame('Empty Row', $row3->title);
        $this->assertSame([], $row3->children);

        // Column 3 (no leaves)
        $col3 = $tree[$pageId][0]->children[1]->children[0];
        $this->assertSame('Full Width Column', $col3->title);
        $this->assertSame([], $col3->children);
    }

    // ---- Permissions ----

    public function testElementsWithCanViewFalseExcluded(): void
    {
        GridElement::add_extension(DenyViewExtension::class);

        try {
            $tree = $this->buildTree();
            $pageId = $this->getPageId();

            $this->assertSame([], $tree[$pageId]);
        } finally {
            GridElement::remove_extension(DenyViewExtension::class);
        }
    }

    // ---- Extension hook ----

    public function testExtensionCanEnrichGridNode(): void
    {
        GridTreeBuilder::add_extension(TestEnricherExtension::class);

        try {
            $tree = $this->buildTree();
            $pageId = $this->getPageId();

            $section = $tree[$pageId][0];
            $data = $section->jsonSerialize();

            $this->assertArrayHasKey('extensions', $data);
            $this->assertArrayHasKey('testEnricher', $data['extensions']);
            $this->assertSame('enriched', $data['extensions']['testEnricher']);

            // Verify enrichment propagates to nested nodes
            $leaf = $section->children[0]->children[0]->children[0];
            $leafData = $leaf->jsonSerialize();

            $this->assertArrayHasKey('extensions', $leafData);
            $this->assertSame('enriched', $leafData['extensions']['testEnricher']);
        } finally {
            GridTreeBuilder::remove_extension(TestEnricherExtension::class);
        }
    }

    // ---- canView: continue vs break ----

    public function testNonViewableElementDoesNotBlockSiblings(): void
    {
        $leaf1Id = $this->idFromFixture(GridElement::class, 'leaf1');
        DenySpecificViewExtension::$denyId = $leaf1Id;
        GridElement::add_extension(DenySpecificViewExtension::class);

        try {
            $tree = $this->buildTree();
            $pageId = $this->getPageId();

            // leaf1 is hidden but leaf2 (sibling) should still appear
            $col1Children = $tree[$pageId][0]->children[0]->children[0]->children;
            $titles = array_map(static fn (GridNode $n): string => $n->title, $col1Children);

            $this->assertNotContains('Text Block', $titles, 'Denied element should be excluded');
            $this->assertContains('Image Block', $titles, 'Sibling element should still appear');
        } finally {
            GridElement::remove_extension(DenySpecificViewExtension::class);
            DenySpecificViewExtension::$denyId = 0;
        }
    }

    // ---- Empty title fallback ----

    public function testEmptyTitleAssignsDefaultOnWrite(): void
    {
        $leaf = $this->objFromFixture(GridElement::class, 'leaf1');
        $leaf->Title = '';
        $leaf->write();

        // ensureDefaultTitle counts same-type siblings under the same parent
        // leaf2 is the only other GridElement under col1 → count 1 + 1 = 2
        $this->assertSame('Grid element 2', $leaf->Title);

        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $node = $tree[$pageId][0]->children[0]->children[0]->children[0];
        $this->assertSame('Grid element 2', $node->title);
    }

    public function testEmptyTitleAssignsDefaultForColumn(): void
    {
        $col = $this->objFromFixture(Column::class, 'col1');
        $col->Title = '';
        $col->write();

        // col2 is the only other Column under row1 → count 1 + 1 = 2
        $this->assertSame('Column 2', $col->Title);

        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $node = $tree[$pageId][0]->children[0]->children[0];
        $this->assertSame('Column 2', $node->title);
    }

    public function testEmptyTitleAssignsDefaultForRow(): void
    {
        $row = $this->objFromFixture(Row::class, 'row1');
        $row->Title = '';
        $row->write();

        // row2 + row3 are the other Rows under section1 → count 2 + 1 = 3
        $this->assertSame('Row 3', $row->Title);

        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $node = $tree[$pageId][0]->children[0];
        $this->assertSame('Row 3', $node->title);
    }

    public function testEmptyTitleAssignsDefaultForSection(): void
    {
        $section = $this->objFromFixture(Section::class, 'section1');
        $section->Title = '';
        $section->write();

        // section2, sidebar_section1, sidebar_section2 share the same parent → count 3 + 1 = 4
        $this->assertSame('Section 4', $section->Title);

        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $node = $tree[$pageId][0];
        $this->assertSame('Section 4', $node->title);
    }

    // ---- Null title fallback (API creation flow) ----

    public function testFreshElementGetsDefaultTitle(): void
    {
        $col = $this->objFromFixture(Column::class, 'col1');

        $element = GridElement::create();
        $element->ParentID = $col->ID;
        $element->ParentClass = Column::class;
        $element->write();

        $this->assertNotEmpty($element->Title, 'Fresh element with null Title should get a default');
        $this->assertStringContainsString('Grid element', $element->Title);
    }

    public function testFreshSectionGetsDefaultTitle(): void
    {
        $page = $this->objFromFixture(TestPage::class, 'testpage');

        Config::modify()->set(Section::class, 'auto_scaffold', false);

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->Zone = 'main';
        $section->write();

        $this->assertNotEmpty($section->Title, 'Fresh section with null Title should get a default');
        $this->assertStringContainsString('Section', $section->Title);
    }

    public function testFreshContentElementGetsDefaultTitle(): void
    {
        $col = $this->objFromFixture(Column::class, 'col1');

        $element = ContentElement::create();
        $element->ParentID = $col->ID;
        $element->ParentClass = Column::class;
        $element->write();

        $this->assertNotEmpty($element->Title, 'Fresh content element with null Title should get a default');
        $this->assertStringContainsString('Content element', $element->Title);
    }

    // ---- getType() ----

    public function testGetTypeReturnsConfiguredSingularName(): void
    {
        $this->assertSame('Section', Section::create()->getType());
        $this->assertSame('Row', Row::create()->getType());
        $this->assertSame('Column', Column::create()->getType());
    }

    public function testGetTypeReturnsFallbackForBaseElement(): void
    {
        $type = GridElement::create()->getType();

        $this->assertNotSame('Unknown', $type, 'Base getType() should not return hardcoded "Unknown"');
        $this->assertNotEmpty($type);
    }

    public function testContentElementGetTypeReturnsContentElement(): void
    {
        $type = ContentElement::create()->getType();

        $this->assertNotSame('Unknown', $type, 'ContentElement should not inherit hardcoded "Unknown"');
        $this->assertNotEmpty($type);
    }

    // ---- Row icon ----

    public function testRowBlockSchemaIconMatchesConfig(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();
        $row = $tree[$pageId][0]->children[0];

        // Row has a configured icon different from the fallback
        $this->assertSame('font-icon-columns', $row->blockSchema['icon']);
    }

    // ---- Column grid settings ----

    public function testColumnHasGridSettings(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();

        // Navigate to first column: Section 1 → Row 1 → Col 1
        $column = $tree[$pageId][0]->children[0]->children[0];

        $this->assertNotNull($column->containerType);
        $this->assertSame('column', $column->containerType->value);
        $this->assertInstanceOf(GridSettings::class, $column->gridSettings);
    }

    // ---- Zone filtering ----

    public function testZoneFilteringExcludesOtherZones(): void
    {
        $page = $this->objFromFixture(TestPage::class, 'testpage');

        /** @var GridTreeBuilder $builder */
        $builder = Injector::inst()->get(GridTreeBuilder::class);
        $tree = $builder->buildForPage($page, 'sidebar');

        $pageId = (int) $page->ID;
        $this->assertArrayHasKey($pageId, $tree);

        $sections = $tree[$pageId];
        $this->assertCount(2, $sections);

        $titles = array_map(static fn (GridNode $n): string => $n->title, $sections);
        $this->assertContains('Sidebar Section 1', $titles);
        $this->assertContains('Sidebar Section 2', $titles);

        // Main-zone sections must not appear in sidebar tree
        $this->assertNotContains('First Section', $titles);
        $this->assertNotContains('Second Section', $titles);
    }

    // ---- Allowed types config ----

    public function testAllowedTypesReflectsConfig(): void
    {
        $tree = $this->buildTree();
        $pageId = $this->getPageId();

        // Section's allowedTypes must contain Row with exact metadata
        $section = $tree[$pageId][0];
        $this->assertNotNull($section->allowedTypes);
        $this->assertArrayHasKey(Row::class, $section->allowedTypes);

        $rowTypeInfo = $section->allowedTypes[Row::class];
        $this->assertSame('Row', $rowTypeInfo['label']);
        $this->assertSame('font-icon-columns', $rowTypeInfo['icon']);
        $this->assertSame(
            'Horizontal container that holds columns within a section',
            $rowTypeInfo['description'],
        );

        // Column's allowedTypes must NOT contain container classes
        // but MUST contain at least one allowed element type
        $column = $tree[$pageId][0]->children[0]->children[0];
        $this->assertNotNull($column->allowedTypes);
        $this->assertNotEmpty($column->allowedTypes);
        $this->assertArrayNotHasKey(Section::class, $column->allowedTypes);
        $this->assertArrayNotHasKey(Row::class, $column->allowedTypes);
        $this->assertArrayNotHasKey(Column::class, $column->allowedTypes);

        // Column's allowedTypes should include ContentElement
        $this->assertArrayHasKey(ContentElement::class, $column->allowedTypes);
    }

    // ---- Empty states ----

    public function testPageWithNoElements(): void
    {
        $page = TestPage::create();
        $page->Title = 'Empty Page';
        $page->write();

        /** @var GridTreeBuilder $builder */
        $builder = Injector::inst()->get(GridTreeBuilder::class);
        $tree = $builder->buildForPage($page);

        // Page has no sections, so the tree contains the page key
        // with an empty element list.
        $pageId = (int) $page->ID;
        $this->assertArrayHasKey($pageId, $tree);
        $this->assertSame([], $tree[$pageId]);
    }
}

/**
 * Test extension that denies canView on all GridElements.
 */
class DenyViewExtension extends Extension
{
    /**
     * @param mixed $member
     */
    public function canView($member): false
    {
        return false;
    }
}

/**
 * Test extension that denies canView for a single element by ID.
 *
 * Unlike {@see DenyViewExtension} which denies ALL elements, this allows
 * testing that continue (not break) is used in the tree builder loop.
 */
class DenySpecificViewExtension extends Extension
{
    public static int $denyId = 0;

    /**
     * @param mixed $member
     */
    public function canView($member): ?bool
    {
        return $this->owner->ID === self::$denyId ? false : null;
    }
}

/**
 * Test extension that enriches element nodes via the updateElementData hook.
 */
class TestEnricherExtension extends Extension
{
    /**
     * @param array<string, mixed> $extensions
     */
    public function updateElementData(GridElement $element, array &$extensions): void
    {
        $extensions['testEnricher'] = 'enriched';
    }
}
