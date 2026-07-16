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
use WeDevelop\Grid\Service\GridTreeService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\VetoViewByTitleExtension;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\NodeType;

#[CoversClass(GridTreeService::class)]
final class GridTreeServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /**
     * Abstains for every element except those titled HIDDEN_TITLE, so a sibling
     * list can mix viewable and non-viewable elements.
     *
     * @var array<class-string, list<class-string>>
     */
    protected static $required_extensions = [
        GridElement::class => [VetoViewByTitleExtension::class],
    ];

    private GridTreeService $builder;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $this->builder = Injector::inst()->get(GridTreeService::class);
    }

    public function testBuildsFullTreeFromPage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column, title: 'Test Content');

        $tree = $this->builder->buildViewableTree($page, 'main');

        $sectionNodes = $tree->nodes;
        self::assertCount(1, $sectionNodes);
        self::assertSame((int) $section->ID, $sectionNodes[0]->getId());
        self::assertSame((int) $page->ID, $sectionNodes[0]->getParentId());
        self::assertSame(ContainerType::Section, $sectionNodes[0]->containerType);

        $rowNodes = $sectionNodes[0]->children;
        self::assertNotNull($rowNodes);
        self::assertCount(1, $rowNodes);
        self::assertSame((int) $row->ID, $rowNodes[0]->getId());
        self::assertSame(ContainerType::Row, $rowNodes[0]->containerType);

        $columnNodes = $rowNodes[0]->children;
        self::assertNotNull($columnNodes);
        self::assertCount(1, $columnNodes);
        self::assertSame((int) $column->ID, $columnNodes[0]->getId());
        self::assertSame(ContainerType::Column, $columnNodes[0]->containerType);

        $contentNodes = $columnNodes[0]->children;
        self::assertNotNull($contentNodes);
        self::assertCount(1, $contentNodes);
        self::assertSame((int) $content->ID, $contentNodes[0]->getId());
        self::assertNull($contentNodes[0]->containerType);
    }

    public function testRespectsZoneFiltering(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page, zone: 'main');
        GridTreeFactory::section($page, zone: 'sidebar');

        $tree = $this->builder->buildViewableTree($page, 'main');

        $sectionNodes = $tree->nodes;
        self::assertCount(1, $sectionNodes);
        self::assertSame(ContainerType::Section, $sectionNodes[0]->containerType);
    }

    public function testEmptyPageReturnsEmptyTree(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $tree = $this->builder->buildViewableTree($page, 'main');

        self::assertSame([], $tree->nodes);
    }

    public function testBuildViewableTreeReturnsRootParentRefForThePage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $tree = $this->builder->buildViewableTree($page, 'main');

        self::assertSame(NodeType::Page, $tree->rootParent->type);
        self::assertSame((int) $page->ID, $tree->rootParent->id);
    }

    public function testPermissionFilteringExcludesNonViewable(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page);

        // Log out so canView returns false (requires CMS_ACCESS)
        $this->logOut();

        $tree = $this->builder->buildViewableTree($page, 'main');

        self::assertSame([], $tree->nodes);
    }

    public function testPermissionFilteringSkipsNonViewableWithoutDroppingLaterSiblings(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page, sort: 1, title: VetoViewByTitleExtension::HIDDEN_TITLE);
        $visible = GridTreeFactory::section($page, sort: 2, title: 'Visible');

        $tree = $this->builder->buildViewableTree($page, 'main');

        $nodes = $tree->nodes;
        self::assertCount(1, $nodes);
        self::assertSame((int) $visible->ID, $nodes[0]->self->id);
    }

    public function testFindViewableContainersOfTypeSkipsNonViewableWithoutDroppingLaterSiblings(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        GridTreeFactory::row($section, sort: 1, title: VetoViewByTitleExtension::HIDDEN_TITLE);
        $visible = GridTreeFactory::row($section, sort: 2, title: 'Visible Row');

        $containers = $this->builder->findViewableContainersOfType($page, 'main', ContainerType::Row);

        self::assertCount(1, $containers);
        self::assertSame((int) $visible->ID, (int) $containers[0]->ID);
    }

    public function testMultipleSectionsWithMultipleRows(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section1 = GridTreeFactory::section($page);
        $section2 = GridTreeFactory::section($page);
        GridTreeFactory::row($section1);
        GridTreeFactory::row($section1);
        GridTreeFactory::row($section2);
        GridTreeFactory::row($section2);

        $tree = $this->builder->buildViewableTree($page, 'main');

        $sectionNodes = $tree->nodes;
        self::assertCount(2, $sectionNodes);
        self::assertCount(2, $sectionNodes[0]->children);
        self::assertCount(2, $sectionNodes[1]->children);
    }

    public function testColumnNodesIncludeGridSettings(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = GridSettings::initial(12);
        GridTreeFactory::column($row, gridSettings: $settings);

        $tree = $this->builder->buildViewableTree($page, 'main');

        $columnNode = $tree->nodes[0]->children[0]->children[0];
        self::assertInstanceOf(GridSettings::class, $columnNode->gridSettings);
        self::assertSame(12, $columnNode->gridSettings->default->width);
    }

    public function testFindDescendantsForPageReturnsAllElementsInZoneUnfilteredInLevelOrder(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $elements = $this->builder->findDescendantsForPage($page, 'main');

        $ids = array_map(static fn (GridElement $e): int => (int) $e->ID, $elements);
        self::assertSame([(int) $section->ID, (int) $row->ID, (int) $column->ID], $ids);
    }

    public function testFindDescendantsForPageIncludesNonViewableElements(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $hidden = GridTreeFactory::row($section, title: VetoViewByTitleExtension::HIDDEN_TITLE);

        $elements = $this->builder->findDescendantsForPage($page, 'main');

        $ids = array_map(static fn (GridElement $e): int => (int) $e->ID, $elements);
        self::assertContains((int) $hidden->ID, $ids);
    }

    public function testFindDescendantsForPageScopesByZoneAtSectionLevel(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $mainSection = GridTreeFactory::section($page, zone: 'main');
        $sidebarSection = GridTreeFactory::section($page, zone: 'sidebar');
        GridTreeFactory::row($sidebarSection);

        $elements = $this->builder->findDescendantsForPage($page, 'main');

        $ids = array_map(static fn (GridElement $e): int => (int) $e->ID, $elements);
        self::assertSame([(int) $mainSection->ID], $ids);
    }

    public function testFindDescendantsForPageReturnsEmptyForEmptyPage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $elements = $this->builder->findDescendantsForPage($page, 'main');

        self::assertSame([], $elements);
    }

    public function testFindDescendantsReturnsFlatSubtreeExcludingRoot(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        GridTreeFactory::column(GridTreeFactory::row($section));

        $descendants = $this->builder->findDescendants($section);

        self::assertCount(2, $descendants); // Row + Column, not the Section itself
        self::assertNotContains((int) $section->ID, array_map(
            static fn (GridElement $e): int => (int) $e->ID,
            $descendants,
        ));
    }

    public function testFindDescendantsReturnsEmptyListForLeafElement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $column = GridTreeFactory::column(GridTreeFactory::row($section));
        $content = GridTreeFactory::contentElement($column);

        self::assertSame([], $this->builder->findDescendants($content));
    }

    public function testFindViewableContainersOfTypeReturnsOnlyRequestedType(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'Top');
        $row1 = GridTreeFactory::row($section, title: 'Upper row');
        $row2 = GridTreeFactory::row($section, title: 'Lower row');
        GridTreeFactory::column($row1);
        GridTreeFactory::column($row2);

        $rows = $this->builder->findViewableContainersOfType($page, 'main', ContainerType::Row);

        self::assertCount(2, $rows);
        $ids = array_map(static fn (GridElement $e): int => (int) $e->ID, $rows);
        self::assertContains((int) $row1->ID, $ids);
        self::assertContains((int) $row2->ID, $ids);
        self::assertContainsOnlyInstancesOf(Row::class, $rows);
    }

    public function testFindViewableContainersOfTypeRespectsZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $mainSection = GridTreeFactory::section($page, zone: 'main', title: 'Main section');
        GridTreeFactory::section($page, zone: 'sidebar', title: 'Sidebar section');

        $mainSections = $this->builder->findViewableContainersOfType($page, 'main', ContainerType::Section);

        self::assertCount(1, $mainSections);
        self::assertSame((int) $mainSection->ID, (int) $mainSections[0]->ID);
    }

    public function testFindViewableContainersOfTypeFiltersOutNonViewable(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page);

        // Log out so canView returns false (requires CMS_ACCESS)
        $this->logOut();

        $sections = $this->builder->findViewableContainersOfType($page, 'main', ContainerType::Section);

        self::assertSame([], $sections);
    }

    public function testFindViewableContainersOfTypeReturnsEmptyForEmptyPage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $rows = $this->builder->findViewableContainersOfType($page, 'main', ContainerType::Row);

        self::assertSame([], $rows);
    }

    public function testAncestorsReturnsContainerChainOutermostFirst(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column);

        $index = $this->builder->indexByKey(GridElement::get());

        // Outermost first: Section → Row → Column. The element's own level is excluded.
        self::assertSame(
            [(int) $section->ID, (int) $row->ID, (int) $column->ID],
            array_map(
                static fn (GridElement $e): int => (int) $e->ID,
                $this->builder->ancestors($content, $index),
            ),
        );
    }

    public function testAncestorsOfSectionIsEmpty(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $index = $this->builder->indexByKey(GridElement::get());

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

        $index = $this->builder->indexByKey(GridElement::get());

        // The section's parent key is "<PageClass>:<collidingId>", which is not a
        // GridElement key — so the same-numbered content element is NOT a false
        // ancestor. Keying the index by bare ID instead of "Class:ID" would make
        // this return [content] and fail.
        self::assertSame([], $this->builder->ancestors($section, $index));
    }
}
