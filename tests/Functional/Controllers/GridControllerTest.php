<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Dev\FunctionalTest;
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
}
