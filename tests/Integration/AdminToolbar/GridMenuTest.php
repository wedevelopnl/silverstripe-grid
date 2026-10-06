<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\AdminToolbar;

use App\MultiZonePage;
use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use WeDevelop\AdminToolbar\ToolbarContext;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\AdminToolbar\GridMenu;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\RestoresGridAdapterEnv;
use WeDevelop\Grid\Tests\Integration\Support\VetoViewByTitleExtension;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridMenu::class)]
final class GridMenuTest extends SapphireTest
{
    use DisablesAutoScaffolding;
    use RestoresGridAdapterEnv;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [VetoViewByTitleExtension::class],
    ];

    private Member $admin;

    private Member $toolbarOnly;

    private SharedBlock $banner;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
        $this->captureGridAdapterEnv();
        $this->admin = $this->createMemberWithPermission('ADMIN');
        $this->toolbarOnly = $this->createMemberWithPermission('ADMIN_TOOLBAR');
        $this->banner = GridTreeFactory::sharedBlock('Banner');
        // The block's own subtree; a placement never expands into it.
        GridTreeFactory::section($this->banner, zone: '', title: 'Banner Section');
    }

    protected function tearDown(): void
    {
        $this->restoreGridAdapterEnv();
        Injector::inst()->unregisterNamedObject(GridAdapterInterface::class);
        parent::tearDown();
    }

    public function testUnsupportedWithoutAPage(): void
    {
        self::assertFalse($this->menu(null)->isSupported());
    }

    public function testUnsupportedForAPageWithoutTheExtension(): void
    {
        // The harness applies GridPageExtension to Page, not to SiteTree.
        self::assertFalse($this->menu(SiteTree::create())->isSupported());
    }

    public function testUnsupportedWhenTheToggleIsEnabledAndUseGridIsOff(): void
    {
        Config::modify()->set(Page::class, 'enable_editor_toggle', true);
        $page = $this->gridPage();
        $page->UseGrid = false;

        self::assertFalse($this->menu($page)->isSupported());
    }

    public function testSupportedWhenTheToggleIsDisabledAndUseGridIsOff(): void
    {
        // The editor ignores a stored UseGrid=false while the toggle is off; so does the menu.
        $page = $this->gridPage();
        $page->UseGrid = false;

        self::assertTrue($this->menu($page)->isSupported());
    }

    public function testUnsupportedWithoutRoots(): void
    {
        self::assertFalse($this->menu($this->page())->isSupported());
    }

    public function testSupportedForAGridPageWithRoots(): void
    {
        self::assertTrue($this->menu($this->gridPage())->isSupported());
    }

    public function testUnsupportedWhenEveryRootLacksAZone(): void
    {
        // A zone-less root breaks the zone rule; until repair-grid-zone fixes it, the menu skips it.
        $page = $this->page();
        $this->unzonedRoot($page, 0, 'Only Unzoned');

        self::assertFalse($this->menu($page)->isSupported());
    }

    public function testUnsupportedWhenTheMemberCanViewNoZonedRoot(): void
    {
        $this->hide('Hero', 'Banner');

        self::assertFalse($this->menu($this->gridPage())->isSupported());
    }

    public function testZonesAreBuiltOncePerMenu(): void
    {
        $menu = $this->menu($this->gridPage());

        self::assertSame($menu->getZones(), $menu->getZones());
    }

    public function testNoZonesWithoutAGridPage(): void
    {
        self::assertSame(0, $this->menu(null)->getZones()->count());
        self::assertSame(0, $this->menu(SiteTree::create())->getZones()->count());
    }

    public function testSingleZoneHasNoHeading(): void
    {
        $zones = $this->menu($this->gridPage())->getZones();

        self::assertSame(['main'], $zones->column('Name'));
        self::assertSame([false], $zones->column('ShowHeading'));
    }

    public function testZonesListMainFirstWithHeadings(): void
    {
        $zones = $this->menu($this->multiZonePage())->getZones();

        self::assertSame(['main', 'banner', 'sidebar'], $zones->column('Name'));
        self::assertSame([true, true, true], $zones->column('ShowHeading'));
        self::assertSame([['section', 'Main']], $this->kindsAndTitles($this->zoneNodes($zones, 'main')));
    }

    public function testZoneTheMemberCannotViewIsOmitted(): void
    {
        $this->hide('Sidebar');

        $zones = $this->menu($this->multiZonePage())->getZones();

        self::assertSame(['main', 'banner'], $zones->column('Name'));
        self::assertSame([true, true], $zones->column('ShowHeading'));
    }

    public function testHeadingCountsOnlyTheZonesTheMemberCanView(): void
    {
        $this->hide('Sidebar', 'Top banner');

        $zones = $this->menu($this->multiZonePage())->getZones();

        self::assertSame(['main'], $zones->column('Name'));
        self::assertSame([false], $zones->column('ShowHeading'));
    }

    public function testTreeShape(): void
    {
        $page = $this->gridPage();
        $this->unzonedRoot($page, 1, 'Unzoned');

        self::assertSame(
            [
                ['Kind' => 'section', 'Title' => 'Hero', 'Span' => null, 'Of' => null, 'Children' => [
                    ['Kind' => 'row', 'Title' => 'Hero Row', 'Span' => null, 'Of' => 12, 'Children' => [
                        ['Kind' => 'column', 'Title' => 'Wide', 'Span' => 8, 'Of' => 12, 'Children' => [
                            ['Kind' => 'element', 'Title' => 'Intro', 'Span' => null, 'Of' => null, 'Children' => []],
                        ]],
                        ['Kind' => 'column', 'Title' => 'Narrow', 'Span' => 4, 'Of' => 12, 'Children' => [
                            ['Kind' => 'element', 'Title' => ContentElement::singleton()->getType(), 'Span' => null, 'Of' => null, 'Children' => []],
                        ]],
                    ]],
                ]],
                ['Kind' => 'shared', 'Title' => 'Banner', 'Span' => null, 'Of' => null, 'Children' => []],
            ],
            $this->tree($this->mainNodes($page, $this->admin)),
        );
    }

    public function testUntitledElementFallsBackToItsType(): void
    {
        $narrow = $this->find($this->mainNodes($this->gridPage(), $this->admin), ['section', 'row', 'column'], 1);

        self::assertSame(['Content element'], $narrow->Children->column('Title'));
    }

    public function testSharedPlacementIsALeafLinkingToTheBlock(): void
    {
        $shared = $this->mainNodes($this->gridPage(), $this->admin)->last();
        self::assertInstanceOf(ArrayData::class, $shared);

        self::assertSame('shared', $shared->Kind);
        self::assertSame([], $this->tree($shared->Children));
        self::assertSame($this->banner->getCMSEditLink(), $shared->Link);
    }

    public function testRowRootedPlacementInsideASectionLinksToTheBlock(): void
    {
        $block = $this->blockRootedAt(Row::create(), 'Row block');
        $page = $this->page();
        $section = GridTreeFactory::section($page, title: 'Host');
        GridTreeFactory::reference($section, $block, zone: '', title: 'Row placement');

        $host = $this->find($this->mainNodes($page, $this->admin), ['section'], 0);

        self::assertSame(
            [['Kind' => 'shared', 'Title' => 'Row placement', 'Span' => null, 'Of' => null, 'Children' => []]],
            $this->tree($host->Children),
        );
        self::assertSame($block->getCMSEditLink(), $host->Children->first()?->Link);
    }

    public function testColumnCarriesItsDefaultViewportLayout(): void
    {
        $page = $this->page();
        $row = GridTreeFactory::row(GridTreeFactory::section($page, title: 'Host'));
        GridTreeFactory::column($row, 1, new GridSettings(new ViewportConfig(6, 3, false)), 'Indented');

        $column = $this->find($this->mainNodes($page, $this->admin), ['section', 'row', 'column'], 0);

        self::assertSame(
            ['Span' => 6, 'Offset' => 3, 'Track' => 9, 'Of' => 12, 'Hidden' => true],
            ['Span' => $column->Span, 'Offset' => $column->Offset, 'Track' => $column->Track, 'Of' => $column->Of, 'Hidden' => $column->Hidden],
        );
    }

    public function testColumnRootedPlacementInsideARowTakesItsColumnLayout(): void
    {
        $root = Column::create();
        $root->setGridSettings(new GridSettings(new ViewportConfig(5, 1, true)));
        $block = $this->blockRootedAt($root, 'Column block');
        $page = $this->page();
        $row = GridTreeFactory::row(GridTreeFactory::section($page, title: 'Host'), title: 'Host Row');
        GridTreeFactory::column($row, 1, new GridSettings(new ViewportConfig(7, 0, true)), 'Local');
        GridTreeFactory::reference($row, $block, zone: '', sort: 2, title: 'Column placement');

        $hostRow = $this->find($this->mainNodes($page, $this->admin), ['section', 'row'], 0);

        self::assertSame(
            [['column', 'Local', 7], ['shared', 'Column placement', 5]],
            array_map(
                static fn (array $node): array => [$node['Kind'], $node['Title'], $node['Span']],
                $this->tree($hostRow->Children),
            ),
        );
        self::assertSame($block->getCMSEditLink(), $hostRow->Children->last()?->Link);
        self::assertSame(6, $hostRow->Children->last()?->Track);
    }

    public function testPlacementWhoseBlockIsGoneHasNoLink(): void
    {
        $page = $this->gridPage();
        // SharedBlock::onBeforeDelete() archives the placements, so only raw DB state strands one.
        SQLUpdate::create(
            '"WeDevelop_Grid_SharedBlockReference"',
            ['"BlockID"' => 999999],
            ['"BlockID"' => $this->banner->ID],
        )->execute();

        $shared = $this->mainNodes($page, $this->admin)->last();
        self::assertInstanceOf(ArrayData::class, $shared);

        self::assertSame('shared', $shared->Kind);
        self::assertNull($shared->Link);
    }

    public function testLinksRequireEditPermission(): void
    {
        // A member without CMS access sees the published page only.
        $page = $this->gridPage();
        $page->publishRecursive();
        $intro = ContentElement::get()->find('Title', 'Intro');
        self::assertInstanceOf(ContentElement::class, $intro);

        $adminLinks = $this->links($this->mainNodes($page, $this->admin));
        $memberLinks = Versioned::withVersionedMode(function () use ($page): array {
            Versioned::set_stage(Versioned::LIVE);

            return $this->links($this->mainNodes($page, $this->toolbarOnly));
        });

        self::assertCount(7, $adminLinks);
        self::assertNotContains(null, $adminLinks);
        self::assertContains($intro->getCMSEditLink(), $adminLinks);
        self::assertSame(array_fill(0, 7, null), $memberLinks);
    }

    public function testNodesTheMemberCannotViewAreOmitted(): void
    {
        $this->hide('Intro');

        $tree = $this->tree($this->mainNodes($this->gridPage(), $this->admin));

        self::assertSame([], $tree[0]['Children'][0]['Children'][0]['Children']);
        self::assertCount(1, $tree[0]['Children'][0]['Children'][1]['Children']);
    }

    public function testColumnCountFollowsTheAdapter(): void
    {
        $this->pinAdapterEnv('tailwind');
        Config::modify()->set(TailwindAdapter::class, 'total_columns', 16);
        // The adapter reads its column count once, when the Injector builds it.
        Injector::inst()->unregisterNamedObject(GridAdapterInterface::class);

        $row = $this->find($this->mainNodes($this->gridPage(), $this->admin), ['section', 'row'], 0);

        self::assertSame(16, $row->Of);
        self::assertSame([8, 4], $row->Children->column('Span'));
    }

    public function testIconsComeFromElementConfig(): void
    {
        Config::modify()->set(Row::class, 'icon', 'font-icon-test-row');

        $nodes = $this->mainNodes($this->gridPage(), $this->admin);

        self::assertSame(['font-icon-block-layout', 'font-icon-block-layout'], $nodes->column('Icon'));
        self::assertSame('font-icon-test-row', $this->find($nodes, ['section', 'row'], 0)->Icon);
        self::assertSame('font-icon-block-content', $this->find($nodes, ['section', 'row', 'column'], 0)->Icon);
        self::assertSame('font-icon-block-content', $this->find($nodes, ['section', 'row', 'column', 'element'], 0)->Icon);
    }

    private function page(): Page
    {
        return $this->objFromFixture(Page::class, 'test_page');
    }

    /**
     * Zone main: Section Hero → Row → Column Wide (8) → Intro
     *                                → Column Narrow (4) → an untitled element
     *            then the Banner placement.
     */
    private function gridPage(): Page
    {
        $page = $this->page();
        $hero = GridTreeFactory::section($page, sort: 1, title: 'Hero');
        $row = GridTreeFactory::row($hero, title: 'Hero Row');
        $wide = GridTreeFactory::column($row, 1, new GridSettings(new ViewportConfig(8, 0, true)), 'Wide');
        $narrow = GridTreeFactory::column($row, 2, new GridSettings(new ViewportConfig(4, 0, true)), 'Narrow');
        GridTreeFactory::contentElement($wide, title: 'Intro');
        $aside = GridTreeFactory::contentElement($narrow, title: 'Aside');
        GridTreeFactory::reference($page, $this->banner, sort: 2, title: 'Banner');

        // The grid gives every element a default title on write, so an untitled
        // element only exists in the database.
        SQLUpdate::create('"WeDevelop_Grid_GridElement"', ['"Title"' => ''], ['"ID"' => $aside->ID])->execute();

        return $page;
    }

    /**
     * A root the zone rule refuses to write, as unrepaired legacy data leaves
     * it: written valid, then stripped of its Zone in the database. It also
     * blocks the page's publish, so it stays out of gridPage().
     */
    private function unzonedRoot(Page $page, int $sort, string $title): void
    {
        $section = GridTreeFactory::section($page, sort: $sort, title: $title);
        SQLUpdate::create('"WeDevelop_Grid_GridElement"', ['"Zone"' => null], ['"ID"' => $section->ID])->execute();
    }

    private function multiZonePage(): MultiZonePage
    {
        $page = MultiZonePage::create();
        $page->Title = 'Multi Zone Page';
        $page->URLSegment = 'multi-zone-page';
        $page->write();

        GridTreeFactory::section($page, zone: 'sidebar', title: 'Sidebar');
        GridTreeFactory::section($page, zone: 'banner', title: 'Top banner');
        GridTreeFactory::section($page, zone: 'main', title: 'Main');

        return $page;
    }

    private function blockRootedAt(GridElement $root, string $title): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock($title);
        $root->ParentID = $block->ID;
        $root->ParentClass = $block::class;
        $root->write();

        return $block;
    }

    private function hide(string ...$titles): void
    {
        Config::modify()->set(VetoViewByTitleExtension::class, 'hidden_titles', $titles);
    }

    private function menu(?SiteTree $page, ?Member $member = null): GridMenu
    {
        return GridMenu::create()->setContext(
            new ToolbarContext($page, $member ?? $this->admin, new HTTPRequest('GET', '/')),
        );
    }

    /**
     * @return ArrayList<ArrayData>
     */
    private function mainNodes(SiteTree $page, Member $member): ArrayList
    {
        return $this->zoneNodes($this->menu($page, $member)->getZones(), 'main');
    }

    /**
     * @param ArrayList<ArrayData> $zones
     * @return ArrayList<ArrayData>
     */
    private function zoneNodes(ArrayList $zones, string $name): ArrayList
    {
        $zone = $zones->find('Name', $name);
        self::assertInstanceOf(ArrayData::class, $zone);

        return $zone->Nodes;
    }

    /**
     * Follows the first node of each listed kind down the tree; `$index` picks
     * among the siblings at the last level.
     *
     * @param ArrayList<ArrayData> $nodes
     * @param list<string> $path
     */
    private function find(ArrayList $nodes, array $path, int $index): ArrayData
    {
        $kind = array_pop($path);

        foreach ($path as $step) {
            $node = $nodes->find('Kind', $step);
            self::assertInstanceOf(ArrayData::class, $node, $step);
            $nodes = $node->Children;
        }

        $found = array_values($nodes->filter('Kind', $kind)->toArray())[$index] ?? null;
        self::assertInstanceOf(ArrayData::class, $found, (string) $kind);

        return $found;
    }

    /**
     * @param ArrayList<ArrayData> $nodes
     * @return list<array{Kind: mixed, Title: mixed, Span: mixed, Of: mixed, Children: list<mixed>}>
     */
    private function tree(ArrayList $nodes): array
    {
        $tree = [];

        foreach ($nodes as $node) {
            $tree[] = [
                'Kind' => $node->Kind,
                'Title' => $node->Title,
                'Span' => $node->Span,
                'Of' => $node->Of,
                'Children' => $this->tree($node->Children),
            ];
        }

        return $tree;
    }

    /**
     * @param ArrayList<ArrayData> $nodes
     * @return list<array{mixed, mixed}>
     */
    private function kindsAndTitles(ArrayList $nodes): array
    {
        return array_map(static fn (array $node): array => [$node['Kind'], $node['Title']], $this->tree($nodes));
    }

    /**
     * @param ArrayList<ArrayData> $nodes
     * @return list<mixed>
     */
    private function links(ArrayList $nodes): array
    {
        $links = [];

        foreach ($nodes as $node) {
            $links = [...$links, $node->Link, ...$this->links($node->Children)];
        }

        return $links;
    }
}
