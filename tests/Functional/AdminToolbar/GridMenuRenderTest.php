<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\AdminToolbar;

use App\MultiZonePage;
use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\AdminToolbar\GridMenu;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * The menu as the toolbar renders it on a published page, through the
 * harness's `$AdminToolbar` in Page.ss.
 */
#[CoversClass(GridMenu::class)]
final class GridMenuRenderTest extends FunctionalTest
{
    use DisablesAutoScaffolding;

    private const string MENU_BUTTON = 'class="ssat:btn" data-toggle-dialog="GridMenu"';

    private const string MENU_DIALOG = '<dialog id="GridMenu"';

    protected static $fixture_file = __DIR__ . '/../../Integration/Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
        $this->logInWithOnly('ADMIN');
    }

    public function testGridPageRendersExactlyOneGridMenu(): void
    {
        [$page, $intro] = $this->gridPage();

        $body = $this->body($page);

        self::assertSame(1, substr_count($body, self::MENU_BUTTON));
        self::assertSame(1, substr_count($body, self::MENU_DIALOG));
        self::assertStringContainsString('data-grid-zone="main"', $body);
        self::assertStringContainsString('data-grid-node="section"', $body);
        self::assertStringContainsString('data-grid-node="shared"', $body);
        self::assertStringContainsString('Intro', $body);
        self::assertStringContainsString(sprintf('href="%s"', $intro->getCMSEditLink()), $body);
        self::assertStringContainsString('grid-template-columns: repeat(12, minmax(0, 1fr));', $body);
        self::assertStringContainsString('grid-column: span 8 / span 8;', $body);
        self::assertStringNotContainsString('Zone: main', $body);
    }

    public function testTheMenuStylesheetIsRequired(): void
    {
        [$page] = $this->gridPage();

        self::assertMatchesRegularExpression(
            '#<link[^>]+href="[^"]*/silverstripe-grid/client/dist/styles/admin-toolbar-menu\.css#',
            $this->body($page),
        );
    }

    public function testColumnRootedPlacementSpansItsColumn(): void
    {
        $root = Column::create();
        $root->setGridSettings(new GridSettings(new ViewportConfig(5, 0, true)));
        $block = GridTreeFactory::sharedBlock('Column block');
        $root->ParentID = $block->ID;
        $root->ParentClass = $block::class;
        $root->write();
        $block->publishRecursive();

        $page = $this->page();
        $row = GridTreeFactory::row(GridTreeFactory::section($page, title: 'Host'));
        GridTreeFactory::column($row, 1, new GridSettings(new ViewportConfig(7, 0, true)));
        GridTreeFactory::reference($row, $block, zone: '', sort: 2);
        $page->publishRecursive();

        self::assertMatchesRegularExpression(
            '#data-grid-node="shared"[^>]*style="--ssgrid-offset: 0; grid-column: span 5 / span 5;"#',
            $this->body($page),
        );
    }

    public function testOffsetColumnSpansItsOffsetAndHiddenColumnIsMarked(): void
    {
        $page = $this->page();
        $row = GridTreeFactory::row(GridTreeFactory::section($page, title: 'Host'));
        GridTreeFactory::column($row, 1, new GridSettings(new ViewportConfig(8, 2, false)));
        $page->publishRecursive();

        self::assertMatchesRegularExpression(
            '#data-grid-node="column" class="ssgrid-toolbar-box" data-hidden style="--ssgrid-offset: 2; grid-column: span 10 / span 10;"#',
            $this->body($page),
        );
    }

    public function testPageThatDoesNotUseTheGridHasNoGridMenu(): void
    {
        Config::modify()->set(Page::class, 'enable_editor_toggle', true);
        [$page] = $this->gridPage();
        $page->UseGrid = false;
        $page->write();
        $page->publishRecursive();

        $body = $this->body($page);

        self::assertStringContainsString('id="admin-toolbar"', $body);
        self::assertStringNotContainsString(self::MENU_DIALOG, $body);
    }

    public function testEveryZoneRendersWithAHeading(): void
    {
        $page = MultiZonePage::create();
        $page->Title = 'Multi Zone Page';
        $page->URLSegment = 'multi-zone-page';
        $page->write();
        GridTreeFactory::section($page, zone: 'main', title: 'Main');
        GridTreeFactory::section($page, zone: 'sidebar', title: 'Sidebar');
        $page->publishRecursive();

        $body = $this->body($page);

        self::assertStringContainsString('data-grid-zone="main"', $body);
        self::assertStringContainsString('data-grid-zone="sidebar"', $body);
        self::assertStringContainsString('Zone: main', $body);
        self::assertStringContainsString('Zone: sidebar', $body);
    }

    public function testMemberWithoutToolbarAccessGetsNoMenu(): void
    {
        [$page] = $this->gridPage();
        $this->logInWithOnly('CMS_ACCESS_CMSMain');

        $body = $this->body($page);

        self::assertStringNotContainsString('id="admin-toolbar"', $body);
        self::assertStringNotContainsString(self::MENU_DIALOG, $body);
    }

    public function testCmsPreviewRendersNoMenu(): void
    {
        [$page] = $this->gridPage();

        $body = $this->body($page, '?CMSPreview=1');

        self::assertStringContainsString('data-element="section"', $body, 'The page itself still renders.');
        self::assertStringNotContainsString(self::MENU_DIALOG, $body);
    }

    private function logInWithOnly(string $permission): void
    {
        $this->session()->set('loggedInAs', $this->logInWithPermission($permission));
    }

    private function page(): Page
    {
        return $this->objFromFixture(Page::class, 'test_page');
    }

    /**
     * Section Hero → Row → Column (8) → Intro, + Column (4); then a placement of
     * a published section-rooted block. Published, since the page is viewed live.
     *
     * @return array{Page, ContentElement}
     */
    private function gridPage(): array
    {
        $page = $this->page();
        $row = GridTreeFactory::row(GridTreeFactory::section($page, sort: 1, title: 'Hero'));
        $wide = GridTreeFactory::column($row, 1, new GridSettings(new ViewportConfig(8, 0, true)));
        GridTreeFactory::column($row, 2, new GridSettings(new ViewportConfig(4, 0, true)));
        $intro = GridTreeFactory::contentElement($wide, title: 'Intro');

        $block = $this->publishedBlock();
        GridTreeFactory::reference($page, $block, sort: 2, title: 'Banner');
        $page->publishRecursive();

        return [$page, $intro];
    }

    private function publishedBlock(): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock('Banner');
        GridTreeFactory::section($block, zone: '', title: 'Banner Section');
        $block->publishRecursive();

        return $block;
    }

    private function body(SiteTree $page, string $query = ''): string
    {
        // Link() carries `?stage=Stage` while the test reads the draft stage.
        $response = $this->get(Controller::join_links($page->Link(), $query));
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }
}
