<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
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

        self::assertSame(NodeType::fromClass($page::class), $tree->rootParent->type);
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

    public function testFindContainersOfTypeSkipsNonViewableWithoutDroppingLaterSiblings(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        GridTreeFactory::row($section, sort: 1, title: VetoViewByTitleExtension::HIDDEN_TITLE);
        $visible = GridTreeFactory::row($section, sort: 2, title: 'Visible Row');

        $containers = $this->builder->findContainersOfType($page, 'main', ContainerType::Row);

        self::assertCount(1, $containers);
        self::assertSame((int) $visible->ID, $containers[0]['id']);
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

    public function testFindColumnsForPageReturnsAllColumns(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);
        $col1 = GridTreeFactory::column($row1);
        $col2 = GridTreeFactory::column($row1);
        $col3 = GridTreeFactory::column($row2);

        $columns = $this->builder->findColumnsForPage($page, 'main');

        self::assertCount(3, $columns);
        $ids = array_map(static fn (Column $c): int => (int) $c->ID, $columns);
        self::assertContains((int) $col1->ID, $ids);
        self::assertContains((int) $col2->ID, $ids);
        self::assertContains((int) $col3->ID, $ids);
    }

    public function testFindColumnsForPageRespectsZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $mainSection = GridTreeFactory::section($page, zone: 'main');
        $sidebarSection = GridTreeFactory::section($page, zone: 'sidebar');
        $mainRow = GridTreeFactory::row($mainSection);
        $sidebarRow = GridTreeFactory::row($sidebarSection);
        GridTreeFactory::column($mainRow);
        GridTreeFactory::column($sidebarRow);

        $columns = $this->builder->findColumnsForPage($page, 'main');

        self::assertCount(1, $columns);
    }

    public function testFindColumnsForPageReturnsEmptyForEmptyPage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $columns = $this->builder->findColumnsForPage($page, 'main');

        self::assertSame([], $columns);
    }

    public function testFindContainersOfTypeReturnsOnlyRequestedType(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'Top');
        $row1 = GridTreeFactory::row($section, title: 'Upper row');
        $row2 = GridTreeFactory::row($section, title: 'Lower row');
        GridTreeFactory::column($row1);
        GridTreeFactory::column($row2);

        $rows = $this->builder->findContainersOfType($page, 'main', ContainerType::Row);

        self::assertCount(2, $rows);
        $titles = array_column($rows, 'title');
        self::assertContains('Upper row', $titles);
        self::assertContains('Lower row', $titles);

        foreach ($rows as $row) {
            self::assertSame('row', $row['type']);
            self::assertIsInt($row['id']);
            self::assertGreaterThan(0, $row['id']);
        }
    }

    public function testFindContainersOfTypeRespectsZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page, zone: 'main', title: 'Main section');
        GridTreeFactory::section($page, zone: 'sidebar', title: 'Sidebar section');

        $mainSections = $this->builder->findContainersOfType($page, 'main', ContainerType::Section);

        self::assertCount(1, $mainSections);
        self::assertSame('Main section', $mainSections[0]['title']);
    }

    public function testFindContainersOfTypeSubstitutesUntitledForEmptyTitle(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page, title: '');

        $sections = $this->builder->findContainersOfType($page, 'main', ContainerType::Section);

        self::assertCount(1, $sections);
        self::assertNotSame('', $sections[0]['title'], 'Empty titles must fall back to a placeholder');
    }

    public function testFindContainersOfTypeFiltersOutNonViewable(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page);

        // Log out so canView returns false (requires CMS_ACCESS)
        $this->logOut();

        $sections = $this->builder->findContainersOfType($page, 'main', ContainerType::Section);

        self::assertSame([], $sections);
    }

    public function testFindContainersOfTypeReturnsEmptyForEmptyPage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $rows = $this->builder->findContainersOfType($page, 'main', ContainerType::Row);

        self::assertSame([], $rows);
    }
}
