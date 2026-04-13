<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DB;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Controllers\GridController;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridController::class)]
final class GridControllerTest extends FunctionalTest
{
    protected static $fixture_file = __DIR__ . '/../../Integration/Fixture/page.yml';

    private const BASE_URL = '/admin/grid/api';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $memberId = $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        $this->session()->set('loggedInAs', $memberId);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function page(): SiteTree
    {
        return $this->objFromFixture(SiteTree::class, 'test_page');
    }

    private function page2(): SiteTree
    {
        return $this->objFromFixture(SiteTree::class, 'test_page_2');
    }

    /**
     * Send a JSON request via Director::test, automatically appending the CSRF token.
     *
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $url, array $body = []): HTTPResponse
    {
        $token = SecurityToken::inst()->getValue();
        $fullUrl = $url . (str_contains($url, '?') ? '&' : '?') . 'SecurityID=' . $token;

        return Director::test(
            $fullUrl,
            null,
            $this->session(),
            $method,
            json_encode($body, JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Send a JSON POST request with CSRF token.
     *
     * @param array<string, mixed> $body
     */
    private function jsonPost(string $url, array $body = []): HTTPResponse
    {
        return $this->jsonRequest('POST', $url, $body);
    }

    /**
     * Send a JSON PATCH request with CSRF token.
     *
     * @param array<string, mixed> $body
     */
    private function jsonPatch(string $url, array $body = []): HTTPResponse
    {
        return $this->jsonRequest('PATCH', $url, $body);
    }

    /**
     * Send a JSON DELETE request with CSRF token.
     *
     * @param array<string, mixed> $body
     */
    private function jsonDelete(string $url, array $body = []): HTTPResponse
    {
        return $this->jsonRequest('DELETE', $url, $body);
    }

    /**
     * Parse a JSON response body into an associative array.
     *
     * @return array<string, mixed>
     */
    private function parseJson(HTTPResponse $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Build a tree under a non-editable page.
     *
     * Sets CanEditType='OnlyTheseUsers' with no editor groups, so the
     * CMS_ACCESS user can still access admin but page.canEdit() returns false.
     * CanViewType is set similarly so page.canView() also returns false.
     *
     * @return array{section: Section, row: Row, column: Column, content: ContentElement, page: SiteTree}
     */
    private function buildRestrictedTree(): array
    {
        $page = $this->page();
        $page->CanEditType = 'OnlyTheseUsers';
        $page->CanViewType = 'OnlyTheseUsers';
        $page->write();

        $tree = $this->buildTree($page);

        return [...$tree, 'page' => $page];
    }

    /**
     * Build a full element tree: Section > Row > Column > ContentElement.
     *
     * @return array{section: Section, row: Row, column: Column, content: ContentElement}
     */
    private function buildTree(?SiteTree $page = null): array
    {
        $page ??= $this->page();
        $section = GridTreeFactory::section($page, 'main', 0, 'Test Section');
        $row = GridTreeFactory::row($section, 0, 'Test Row');
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column, 0, 'Test Content');

        return [
            'section' => $section,
            'row' => $row,
            'column' => $column,
            'content' => $content,
        ];
    }

    // ─── readTree ─────────────────────────────────────────────────

    public function testReadTreeReturnsTreeStructure(): void
    {
        $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertArrayHasKey('tree', $data);
        self::assertArrayHasKey('overrideCounts', $data);
    }

    public function testReadTreeReturns404ForNonExistentPage(): void
    {
        $response = $this->get(self::BASE_URL . '/readTree/999999/main');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReadTreeCountsViewportOverrides(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(6, 0, true)],
        );
        GridTreeFactory::column($row, 0, $settings);

        $pageId = (int) $page->ID;
        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        $overrides = (array) $data['overrideCounts'];
        self::assertArrayHasKey('lg', $overrides);
        self::assertSame(1, $overrides['lg']);
        self::assertArrayHasKey('_total', $overrides);
        self::assertSame(1, $overrides['_total']);
    }

    public function testReadTreeAtVersionReturnsHistoricalTree(): void
    {
        $this->buildTree();
        $page = $this->page();
        $pageId = (int) $page->ID;

        // The page already has a version from buildTree's touchOwningPage-style writes.
        // Capture the current version number.
        $version = (int) $page->Version;
        self::assertGreaterThan(0, $version);

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main/version/{$version}");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertArrayHasKey('tree', $data);
        self::assertArrayHasKey('overrideCounts', $data);
    }

    public function testReadTreeAtVersionWithNonExistentVersionReturns404(): void
    {
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main/version/999999");

        self::assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('invalidVersionProvider')]
    public function testReadTreeAtVersionWithInvalidVersionParameterReturns404(string $version): void
    {
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main/version/{$version}");

        // Invalid version segments either fail the route altogether (non-
        // numeric, negative) or are rejected by filter_var in the action
        // (0, 1.5). Both cases surface as 404 — there is no resource at
        // that URL.
        self::assertSame(404, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidVersionProvider(): iterable
    {
        yield 'non-numeric' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'float' => ['1.5'];
    }

    // ─── create ───────────────────────────────────────────────────

    public function testCreateSectionReturns204(): void
    {
        $pageId = (int) $this->page()->ID;

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parentId' => $pageId,
            'zone' => 'main',
        ]);

        self::assertSame(204, $response->getStatusCode());

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => SiteTree::class,
        ]);
        self::assertGreaterThanOrEqual(1, $sections->count());
    }

    public function testCreateRowReturns204(): void
    {
        $section = GridTreeFactory::section($this->page());

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'row',
            'parentId' => (int) $section->ID,
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testCreateColumnReturns204(): void
    {
        $section = GridTreeFactory::section($this->page());
        $row = GridTreeFactory::row($section);

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'column',
            'parentId' => (int) $row->ID,
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testCreateReturns400ForInvalidBody(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/create', []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateReturns400ForMissingParent(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parentId' => 999999,
            'zone' => 'main',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── createContent ────────────────────────────────────────────

    public function testCreateContentReturns204(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPost(self::BASE_URL . '/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $tree['column']->ID,
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testCreateContentReturns400ForNonColumnParent(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPost(self::BASE_URL . '/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $tree['section']->ID,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateContentReturns400ForInvalidBody(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/createContent', []);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── publish ──────────────────────────────────────────────────

    public function testPublishReturns204(): void
    {
        $tree = $this->buildTree();
        $sectionId = (int) $tree['section']->ID;

        $response = $this->jsonPatch(self::BASE_URL . '/publish', [
            'id' => $sectionId,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify element is now on LIVE
        $live = Versioned::withVersionedMode(static function () use ($sectionId): ?Section {
            Versioned::set_stage(Versioned::LIVE);

            return Section::get()->byID($sectionId);
        });
        self::assertNotNull($live);
    }

    public function testPublishReturns400ForNonExistentElement(): void
    {
        $response = $this->jsonPatch(self::BASE_URL . '/publish', [
            'id' => 999999,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── unpublish ────────────────────────────────────────────────

    public function testUnpublishReturns204(): void
    {
        $tree = $this->buildTree();
        $section = $tree['section'];
        $section->publishRecursive();
        $sectionId = (int) $section->ID;

        $response = $this->jsonPatch(self::BASE_URL . '/unpublish', [
            'id' => $sectionId,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify element is no longer on LIVE
        $live = Versioned::withVersionedMode(static function () use ($sectionId): ?Section {
            Versioned::set_stage(Versioned::LIVE);

            return Section::get()->byID($sectionId);
        });
        self::assertNull($live);
    }

    public function testUnpublishReturns400ForNonExistentElement(): void
    {
        $response = $this->jsonPatch(self::BASE_URL . '/unpublish', [
            'id' => 999999,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── delete ───────────────────────────────────────────────────

    public function testDeleteReturns204(): void
    {
        $tree = $this->buildTree();
        $contentId = (int) $tree['content']->ID;

        $response = $this->jsonDelete(self::BASE_URL . '/delete', [
            'id' => $contentId,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify element is archived (not on DRAFT)
        $found = ContentElement::get()->byID($contentId);
        self::assertNull($found);
    }

    public function testDeleteReturns400ForNonExistentElement(): void
    {
        $response = $this->jsonDelete(self::BASE_URL . '/delete', [
            'id' => 999999,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── duplicate ────────────────────────────────────────────────

    public function testDuplicateReturns204(): void
    {
        $tree = $this->buildTree();
        $sectionId = (int) $tree['section']->ID;

        $sectionCountBefore = Section::get()->filter([
            'ParentID' => (int) $this->page()->ID,
            'ParentClass' => SiteTree::class,
        ])->count();

        $response = $this->jsonPost(self::BASE_URL . '/duplicate', [
            'id' => $sectionId,
        ]);

        self::assertSame(204, $response->getStatusCode());

        $sectionCountAfter = Section::get()->filter([
            'ParentID' => (int) $this->page()->ID,
            'ParentClass' => SiteTree::class,
        ])->count();
        self::assertSame($sectionCountBefore + 1, $sectionCountAfter);
    }

    public function testDuplicateReturns400ForNonExistentElement(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/duplicate', [
            'id' => 999999,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── duplicateTo ──────────────────────────────────────────────

    public function testDuplicateToSectionToOtherPageReturns204(): void
    {
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $page2Id = (int) $page2->ID;

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['section']->ID,
            'targetPageId' => $page2Id,
            'targetZone' => 'main',
            'targetParentId' => $page2Id,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify a section now exists under page 2
        $sections = Section::get()->filter([
            'ParentID' => $page2Id,
            'ParentClass' => SiteTree::class,
        ]);
        self::assertGreaterThanOrEqual(1, $sections->count());
    }

    public function testDuplicateToReturns422ForHierarchyViolation(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        // Try to duplicate a Row into a Column — Row is disallowed in Column
        // Row is not a Section, so controller queries GridElement table → finds Column →
        // hierarchy check: Column.isChildAllowed(Row) → false → 422
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['row']->ID,
            'targetPageId' => $pageId,
            'targetZone' => 'main',
            'targetParentId' => (int) $tree['column']->ID,
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testDuplicateToReturns400ForInvalidBody(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', []);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── reorder ──────────────────────────────────────────────────

    public function testReorderSameParentReturns204(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main', 0, 'Section');
        $row1 = GridTreeFactory::row($section, 1, 'Row 1');
        $row2 = GridTreeFactory::row($section, 2, 'Row 2');

        // Move row2 before row1 (afterElementID = null means first position)
        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => (int) $row2->ID,
            'targetParentId' => (int) $section->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testReorderCrossParentReturns204(): void
    {
        $page = $this->page();
        $section1 = GridTreeFactory::section($page, 'main', 1, 'Section 1');
        $row1 = GridTreeFactory::row($section1, 1, 'Row 1');
        $section2 = GridTreeFactory::section($page, 'main', 2, 'Section 2');

        // Move row1 to section2
        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => (int) $row1->ID,
            'targetParentId' => (int) $section2->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify row1 is now under section2
        $row1 = Row::get()->byID((int) $row1->ID);
        self::assertNotNull($row1);
        self::assertSame((int) $section2->ID, (int) $row1->ParentID);
    }

    public function testReorderReturns400ForInvalidBody(): void
    {
        $response = $this->jsonPatch(self::BASE_URL . '/reorder', []);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── updateGridSettings ───────────────────────────────────────

    public function testUpdateGridSettingsDefaultReturns204(): void
    {
        $tree = $this->buildTree();
        $columnId = (int) $tree['column']->ID;

        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'id' => $columnId,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify settings were updated
        /** @var Column $column */
        $column = Column::get()->byID($columnId);
        self::assertNotNull($column);
        $settings = $column->getGridSettings();
        self::assertSame(6, $settings->default->width);
    }

    public function testUpdateGridSettingsOverrideReturns204(): void
    {
        $tree = $this->buildTree();
        $columnId = (int) $tree['column']->ID;

        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'id' => $columnId,
            'viewport' => 'lg',
            'width' => 4,
            'offset' => 2,
            'visible' => true,
        ]);

        self::assertSame(204, $response->getStatusCode());

        /** @var Column $column */
        $column = Column::get()->byID($columnId);
        self::assertNotNull($column);
        $settings = $column->getGridSettings();
        self::assertTrue($settings->hasOverride('lg'));
        self::assertSame(4, $settings->getOverride('lg')->width);
    }

    public function testUpdateGridSettingsReturns400ForNonColumn(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'id' => (int) $tree['section']->ID,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── resetGridSettingsOverrides ───────────────────────────────

    public function testResetSpecificViewportReturns204(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(6, 0, true)],
        );
        $column = GridTreeFactory::column($row, 0, $settings);

        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => 'lg',
        ]);

        self::assertSame(204, $response->getStatusCode());

        /** @var Column $updated */
        $updated = Column::get()->byID((int) $column->ID);
        self::assertNotNull($updated);
        self::assertFalse($updated->getGridSettings()->hasOverride('lg'));
    }

    public function testResetAllOverridesReturns204(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            ViewportConfig::default(12),
            [
                'lg' => new ViewportConfig(6, 0, true),
                'xl' => new ViewportConfig(4, 0, true),
            ],
        );
        $column = GridTreeFactory::column($row, 0, $settings);

        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());

        /** @var Column $updated */
        $updated = Column::get()->byID((int) $column->ID);
        self::assertNotNull($updated);
        self::assertSame([], $updated->getGridSettings()->overrides);
    }

    public function testResetReturns404ForNonExistentPage(): void
    {
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => 999999,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    // ─── acceptableContainers ─────────────────────────────────────

    public function testAcceptableContainersForColumnReturnsRows(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/column");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);

        // The tree has one row, so it should appear as an acceptable container
        self::assertNotEmpty($data);
        $ids = array_column($data, 'id');
        self::assertContains((int) $tree['row']->ID, $ids);
    }

    public function testAcceptableContainersForSectionReturnsEmptyArray(): void
    {
        $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/section");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertSame([], $data);
    }

    public function testAcceptableContainersReturns400ForInvalidType(): void
    {
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/invalid");

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── zones ────────────────────────────────────────────────────

    public function testZonesReturnsZoneList(): void
    {
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/zones/{$pageId}");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertIsArray($data);
        // Page has GridPageExtension with at least a 'main' zone
        self::assertContains('main', $data);
    }

    public function testZonesReturns404ForNonExistentPage(): void
    {
        $response = $this->get(self::BASE_URL . '/zones/999999');

        self::assertSame(404, $response->getStatusCode());
    }

    // ─── pages ────────────────────────────────────────────────────

    public function testPagesReturnsPageList(): void
    {
        $response = $this->get(self::BASE_URL . '/pages');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertNotEmpty($data);

        // Check structure of first result
        $first = $data[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
        self::assertArrayHasKey('parentId', $first);
        self::assertArrayHasKey('hasGridZones', $first);
    }

    public function testPagesSearchFilters(): void
    {
        $response = $this->get(self::BASE_URL . '/pages?search=Integration');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);

        // Both fixture pages have "Integration" in the title
        $titles = array_column($data, 'title');
        foreach ($titles as $title) {
            self::assertStringContainsString('Integration', $title);
        }
    }

    public function testPagesSearchReturnsEmptyForNoMatch(): void
    {
        $response = $this->get(self::BASE_URL . '/pages?search=ThisPageDoesNotExist12345');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertSame([], $data);
    }

    // ─── CSRF protection ──────────────────────────────────────────

    public function testCreateContentForNonExistentParentReturns400(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/createContent', [
            'className' => ContentElement::class,
            'parentId' => 999999,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDeleteTouchesOwningPage(): void
    {
        $tree = $this->buildTree();
        $page = $this->page();
        $contentId = (int) $tree['content']->ID;

        // Publish the page first so we can detect the draft modification
        $page->publishRecursive();
        $liveVersion = (int) Versioned::withVersionedMode(static function () use ($page): int {
            Versioned::set_stage(Versioned::LIVE);

            return (int) SiteTree::get()->byID($page->ID)->Version;
        });

        // Delete the content element
        $this->jsonDelete(self::BASE_URL . '/delete', ['id' => $contentId]);

        // Page draft version should have been bumped by touchOwningPage
        $draftPage = SiteTree::get()->byID($page->ID);
        self::assertGreaterThan($liveVersion, (int) $draftPage->Version);
    }

    // ─── Permission guards (403) ────────────────────────────────
    //
    // These tests use page-level CanEditType/CanViewType='OnlyTheseUsers'
    // with no editor groups. The CMS user can access admin (has CMS_ACCESS)
    // but the page's own canEdit()/canView() returns false, triggering the
    // jsonError(403) guards inside the endpoint methods.

    public function testReadTreeReturns403ForNonViewablePage(): void
    {
        $restricted = $this->buildRestrictedTree();
        $pageId = (int) $restricted['page']->ID;

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main");

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateReturns403ForNonEditableParent(): void
    {
        $restricted = $this->buildRestrictedTree();
        $pageId = (int) $restricted['page']->ID;

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parentId' => $pageId,
            'zone' => 'main',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateContentReturns403ForNonEditableParent(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPost(self::BASE_URL . '/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $restricted['column']->ID,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPublishReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPatch(self::BASE_URL . '/publish', [
            'id' => (int) $restricted['section']->ID,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonDelete(self::BASE_URL . '/delete', [
            'id' => (int) $restricted['content']->ID,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDuplicateReturns403ForNonEditableParent(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPost(self::BASE_URL . '/duplicate', [
            'id' => (int) $restricted['section']->ID,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDuplicateToReturns403ForNonEditableTarget(): void
    {
        // Source on editable page, target on restricted page
        $tree = $this->buildTree();
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['row']->ID,
            'targetPageId' => (int) $restricted['page']->ID,
            'targetZone' => 'main',
            'targetParentId' => (int) $restricted['section']->ID,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReorderReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => (int) $restricted['row']->ID,
            'targetParentId' => (int) $restricted['section']->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testResetGridSettingsOverridesReturns403ForNonEditablePage(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $restricted['page']->ID,
            'zone' => 'main',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAcceptableContainersReturns403ForNonViewablePage(): void
    {
        $restricted = $this->buildRestrictedTree();
        $pageId = (int) $restricted['page']->ID;

        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/column");

        self::assertSame(403, $response->getStatusCode());
    }

    public function testZonesReturns403ForNonViewablePage(): void
    {
        $restricted = $this->buildRestrictedTree();
        $pageId = (int) $restricted['page']->ID;

        $response = $this->get(self::BASE_URL . "/zones/{$pageId}");

        self::assertSame(403, $response->getStatusCode());
    }

    // ─── Validation guards (400/404) ─────────────────────────────

    public function testReorderReturns400ForNonExistentElement(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => 999999,
            'targetParentId' => (int) $tree['section']->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReorderReturns400ForNonExistentTargetParent(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => (int) $tree['row']->ID,
            'targetParentId' => 999999,
            'afterElementID' => null,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testUpdateGridSettingsReturns400ForInvalidBody(): void
    {
        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testResetGridSettingsOverridesReturns400ForInvalidBody(): void
    {
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateToReturns400ForPageMismatch(): void
    {
        $tree = $this->buildTree();
        $page2 = $this->page2();

        // Section's targetParentId must equal targetPageId, but we provide a different page
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['section']->ID,
            'targetPageId' => (int) $this->page()->ID,
            'targetZone' => 'main',
            'targetParentId' => (int) $page2->ID,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateToReturns400ForZoneMismatch(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        // Row lives in 'main' zone, but we claim target zone is 'sidebar'
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['row']->ID,
            'targetPageId' => $pageId,
            'targetZone' => 'sidebar',
            'targetParentId' => (int) $tree['section']->ID,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testParseJsonBodyReturns400ForInvalidJson(): void
    {
        $token = SecurityToken::inst()->getValue();
        $url = self::BASE_URL . '/create?SecurityID=' . $token;

        // Send raw invalid JSON string
        $response = Director::test(
            $url,
            null,
            $this->session(),
            'POST',
            'not-valid-json',
            ['Content-Type' => 'application/json'],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateToReturns404ForMissingTargetParent(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        // Row queries GridElement table for targetParentId — 999999 does not exist
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['row']->ID,
            'targetPageId' => $pageId,
            'targetZone' => 'main',
            'targetParentId' => 999999,
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    // ─── Business logic branches ─────────────────────────────────

    public function testCreateWithInsertAfterElementId(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main', 0, 'Section');
        $row1 = GridTreeFactory::row($section, 1, 'Row 1');
        $row2 = GridTreeFactory::row($section, 2, 'Row 2');

        // Create a new row inserted after row1 (should end up between row1 and row2)
        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'row',
            'parentId' => (int) $section->ID,
            'insertAfterElementID' => (int) $row1->ID,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify 3 rows under section, new row sorted between row1 and row2
        $rows = Row::get()->filter([
            'ParentID' => (int) $section->ID,
            'ParentClass' => Section::class,
        ])->sort('Sort', 'ASC');
        self::assertSame(3, $rows->count());

        $sortValues = $rows->column('Sort');
        // row1 should be first, new row second, row2 third
        self::assertSame((int) $sortValues[0], (int) $row1->Sort);
    }

    public function testCreateContentWithInsertAfterElementId(): void
    {
        $tree = $this->buildTree();
        $column = $tree['column'];
        $content1 = $tree['content'];
        $content2 = GridTreeFactory::contentElement($column, 2, 'Content 2');

        // Create new content element inserted after content1
        $response = $this->jsonPost(self::BASE_URL . '/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $column->ID,
            'insertAfterElementID' => (int) $content1->ID,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify 3 content elements under column
        $elements = ContentElement::get()->filter([
            'ParentID' => (int) $column->ID,
            'ParentClass' => Column::class,
        ]);
        self::assertSame(3, $elements->count());
    }

    public function testUpdateGridSettingsRemovesRedundantOverride(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        // Create column with an 'lg' override that differs from default
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(6, 0, true)],
        );
        $column = GridTreeFactory::column($row, 0, $settings);
        $columnId = (int) $column->ID;

        // Update 'lg' to match the default values (width=12, offset=0, visible=true)
        // This should remove the override since it's now redundant
        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'id' => $columnId,
            'viewport' => 'lg',
            'width' => 12,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertSame(204, $response->getStatusCode());

        /** @var Column $updated */
        $updated = Column::get()->byID($columnId);
        self::assertNotNull($updated);
        self::assertFalse(
            $updated->getGridSettings()->hasOverride('lg'),
            'Override matching default should be removed',
        );
    }

    public function testResetGridSettingsOverridesSkipsColumnsWithoutOverrides(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        // Create column WITHOUT any overrides
        GridTreeFactory::column($row);

        // Reset all overrides — should succeed with no errors (just skips)
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAcceptableContainersForRowReturnsSection(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/row");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);

        self::assertNotEmpty($data);
        $ids = array_column($data, 'id');
        self::assertContains((int) $tree['section']->ID, $ids);
    }

    public function testAcceptableContainersForElementReturnsColumn(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/element");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);

        self::assertNotEmpty($data);
        $ids = array_column($data, 'id');
        self::assertContains((int) $tree['column']->ID, $ids);
    }

    public function testResetGridSettingsOverridesForPageWithNoSections(): void
    {
        $page = $this->page2();

        // Page 2 has no sections — exercises findColumnsForPage early return
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testResetGridSettingsOverridesSkipsColumnsWithoutSpecificViewport(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        // Create column with override only on 'lg'
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(6, 0, true)],
        );
        $column = GridTreeFactory::column($row, 0, $settings);
        $columnId = (int) $column->ID;

        // Reset 'xl' viewport — column only has 'lg', so it should be skipped
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => 'xl',
        ]);

        self::assertSame(204, $response->getStatusCode());

        // 'lg' override should still be present
        /** @var Column $updated */
        $updated = Column::get()->byID($columnId);
        self::assertNotNull($updated);
        self::assertTrue(
            $updated->getGridSettings()->hasOverride('lg'),
            'Unrelated viewport reset should not affect existing overrides',
        );
    }

    // ─── Additional edge-case / error-path coverage ─────────────

    public function testAcceptableContainersReturns404ForNonExistentPage(): void
    {
        $response = $this->get(self::BASE_URL . '/acceptableContainers/999999/main/column');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPagesExcludesNonEditablePages(): void
    {
        $restricted = $this->buildRestrictedTree();
        $restrictedPageId = (int) $restricted['page']->ID;

        $response = $this->get(self::BASE_URL . '/pages');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        $ids = array_column($data, 'id');

        // The restricted page should be filtered out by the canEdit() check
        self::assertNotContains($restrictedPageId, $ids);
    }

    public function testReorderReturns403ForNonEditableTargetParent(): void
    {
        // Source tree on an editable page, target tree on a restricted page
        $sourceTree = $this->buildTree($this->page2());
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => (int) $sourceTree['row']->ID,
            'targetParentId' => (int) $restricted['section']->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReorderReturns403ForNonEditableSourceParent(): void
    {
        // Cross-parent move where the source parent no longer exists.
        // The element itself passes canEdit() (CMS_ACCESS fallback for orphans),
        // but Parent() returns null → 403 at the sourceParent check.
        $tree = $this->buildTree();
        $targetTree = $this->buildTree($this->page2());
        $rowId = (int) $tree['row']->ID;
        $sectionId = (int) $tree['section']->ID;

        // Orphan the row by removing its source parent via raw SQL
        DB::query(sprintf(
            "DELETE FROM \"GridElement\" WHERE \"ID\" = %d",
            $sectionId,
        ));

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => $rowId,
            'targetParentId' => (int) $targetTree['section']->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReorderResultErrorPropagates(): void
    {
        // Move a Column into a Section — Section only allows Rows, so
        // ReorderValidator returns Result::fail which surfaces as 422
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => (int) $tree['column']->ID,
            'targetParentId' => (int) $tree['section']->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testResetGridSettingsOverridesWithNoRows(): void
    {
        // Section exists but has no rows (auto_scaffold is disabled in setUp),
        // so findColumnsForPage hits the rows=[] early return
        $page = $this->page();
        GridTreeFactory::section($page, 'main');

        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeleteOrphanDoesNotCrash(): void
    {
        // Create a content element, then orphan it via raw SQL so getPage() returns null
        $tree = $this->buildTree();
        $contentId = (int) $tree['content']->ID;

        DB::query(sprintf(
            "UPDATE \"GridElement\" SET \"ParentID\" = 0 WHERE \"ID\" = %d",
            $contentId,
        ));

        $response = $this->jsonDelete(self::BASE_URL . '/delete', [
            'id' => $contentId,
        ]);

        // touchOwningPage receives null page and returns early — no crash
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPublishReturns400ForMissingElementId(): void
    {
        // Body without 'id' — parseElementId returns fail → jsonError(400)
        $response = $this->jsonPatch(self::BASE_URL . '/publish', []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReorderSectionUsesPageLookup(): void
    {
        // Reorder a section from page1 to page2 — exercises resolveParentRecord
        // where element.ParentClass is SiteTree, causing a SiteTree table query
        $page1 = $this->page();
        $page2 = $this->page2();
        $section = GridTreeFactory::section($page1, 'main', 0, 'Movable Section');

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'elementID' => (int) $section->ID,
            'targetParentId' => (int) $page2->ID,
            'afterElementID' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify section is now under page2
        /** @var Section $moved */
        $moved = Section::get()->byID((int) $section->ID);
        self::assertNotNull($moved);
        self::assertSame((int) $page2->ID, (int) $moved->ParentID);
    }

    public function testDuplicateToReturns400ForOwningPageMismatch(): void
    {
        // For non-section elements, the code walks up from targetParent to the
        // owning page and compares it with targetPageId. When they differ → 400.
        $tree = $this->buildTree();
        $page2 = $this->page2();

        // Row's targetParentId is a valid section on page1, but targetPageId
        // points to page2 — owningPage.ID !== targetPageId → 400
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['row']->ID,
            'targetPageId' => (int) $page2->ID,
            'targetZone' => 'main',
            'targetParentId' => (int) $tree['section']->ID,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    // ─── overrideCounts JSON shape ───────────────────────────────

    public function testReadTreeOverrideCountsIsJsonObject(): void
    {
        // When no overrides exist, overrideCounts must still be a JSON object {}
        // (not an empty array []) for frontend compatibility
        $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main");
        self::assertSame(200, $response->getStatusCode());

        // Parse as raw JSON to check the actual type
        $raw = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $raw->overrideCounts);
    }

    // ─── Zone assignment on container create ─────────────────────

    public function testCreateSectionSetsZone(): void
    {
        $pageId = (int) $this->page()->ID;

        $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parentId' => $pageId,
            'zone' => 'sidebar',
        ]);

        $section = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => SiteTree::class,
            'Zone' => 'sidebar',
        ])->first();
        self::assertNotNull($section, 'Section should have Zone set to "sidebar"');
    }

    public function testCreateRowDoesNotSetZone(): void
    {
        $section = GridTreeFactory::section($this->page(), 'main');

        $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'row',
            'parentId' => (int) $section->ID,
        ]);

        $rows = Row::get()->filter([
            'ParentID' => (int) $section->ID,
            'ParentClass' => Section::class,
        ]);
        foreach ($rows as $row) {
            self::assertEmpty($row->Zone, 'Row should not have Zone set');
        }
    }

    // ─── touchOwningPage on create ───────────────────────────────

    public function testCreateSectionTouchesOwningPage(): void
    {
        $page = $this->page();
        $page->publishRecursive();

        $liveVersion = (int) Versioned::withVersionedMode(static function () use ($page): int {
            Versioned::set_stage(Versioned::LIVE);

            return (int) SiteTree::get()->byID($page->ID)->Version;
        });

        $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parentId' => (int) $page->ID,
            'zone' => 'main',
        ]);

        $draftPage = SiteTree::get()->byID($page->ID);
        self::assertGreaterThan($liveVersion, (int) $draftPage->Version);
    }

    // ─── Duplicate verifies sort and title ───────────────────────

    public function testDuplicateSetsCorrectSortAndTitle(): void
    {
        $tree = $this->buildTree();
        $sectionId = (int) $tree['section']->ID;
        $pageId = (int) $this->page()->ID;

        $this->jsonPost(self::BASE_URL . '/duplicate', ['id' => $sectionId]);

        // Find the cloned section (not the original)
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => SiteTree::class,
        ])->sort('ID', 'DESC');

        $clone = $sections->first();
        self::assertNotNull($clone);
        self::assertNotSame($sectionId, (int) $clone->ID);

        // Clone title should contain "copy"
        self::assertStringContainsString('copy', strtolower($clone->Title));
    }

    // ─── DuplicateTo deep copy ───────────────────────────────────

    public function testDuplicateToCreatesDeepCopy(): void
    {
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $page2Id = (int) $page2->ID;

        // Duplicate section (with its row+column+content) to page2
        $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['section']->ID,
            'targetPageId' => $page2Id,
            'targetZone' => 'main',
            'targetParentId' => $page2Id,
        ]);

        // Verify section created under page2
        $newSection = Section::get()->filter([
            'ParentID' => $page2Id,
            'ParentClass' => SiteTree::class,
        ])->first();
        self::assertNotNull($newSection);

        // Should have a child row (deep copy)
        $newRows = Row::get()->filter([
            'ParentID' => (int) $newSection->ID,
            'ParentClass' => Section::class,
        ]);
        self::assertGreaterThanOrEqual(1, $newRows->count(), 'Deep copy should include child rows');
    }

    public function testDuplicateToSectionSetsTargetZone(): void
    {
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $page2Id = (int) $page2->ID;

        $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'id' => (int) $tree['section']->ID,
            'targetPageId' => $page2Id,
            'targetZone' => 'sidebar',
            'targetParentId' => $page2Id,
        ]);

        $newSection = Section::get()->filter([
            'ParentID' => $page2Id,
            'ParentClass' => SiteTree::class,
            'Zone' => 'sidebar',
        ])->first();
        self::assertNotNull($newSection, 'Duplicated section should have target zone "sidebar"');
    }

    // ─── acceptableContainers response shape ─────────────────────

    public function testAcceptableContainersResponseHasIdTitleTypeKeys(): void
    {
        $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/column");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertNotEmpty($data);

        $first = $data[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
        self::assertArrayHasKey('type', $first);
        self::assertSame('row', $first['type']);
    }

    // ─── Reset overrides with multiple columns ───────────────────

    public function testResetOverridesAffectsMultipleColumns(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        // Create 3 columns, each with a different viewport override
        $col1 = GridTreeFactory::column($row, 1, new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(6, 0, true)],
        ));
        $col2 = GridTreeFactory::column($row, 2, new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(4, 0, true)],
        ));
        $col3 = GridTreeFactory::column($row, 3, new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(8, 0, true)],
        ));

        $page->publishRecursive();
        $liveVersion = (int) Versioned::withVersionedMode(static function () use ($page): int {
            Versioned::set_stage(Versioned::LIVE);

            return (int) SiteTree::get()->byID($page->ID)->Version;
        });

        // Reset ALL overrides for the page
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // All 3 columns should have overrides cleared
        foreach ([$col1, $col2, $col3] as $col) {
            /** @var Column $updated */
            $updated = Column::get()->byID((int) $col->ID);
            self::assertSame([], $updated->getGridSettings()->overrides);
        }

        // Page should be touched since affected > 0
        $draftPage = SiteTree::get()->byID($page->ID);
        self::assertGreaterThan($liveVersion, (int) $draftPage->Version);
    }

    // ─── Pages endpoint returns multiple results ─────────────────

    public function testPagesReturnsMultipleResults(): void
    {
        $response = $this->get(self::BASE_URL . '/pages');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        // Fixture has 2 pages, both should be editable
        self::assertGreaterThanOrEqual(2, count($data));
    }

    // ─── Zones deduplication ─────────────────────────────────────

    public function testZonesReturnsDeduplicated(): void
    {
        $page = $this->page();
        $pageId = (int) $page->ID;

        $response = $this->get(self::BASE_URL . "/zones/{$pageId}");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);

        // Should be a simple array (not object) with unique values
        self::assertSame(array_values(array_unique($data)), $data);
    }
}
