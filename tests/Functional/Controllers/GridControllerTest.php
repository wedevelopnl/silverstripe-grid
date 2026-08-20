<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Controllers\GridController;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\DenyCreateExtension;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Unit\Support\DirectGridElementStub;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridController::class)]
final class GridControllerTest extends FunctionalTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../../Integration/Fixture/page.yml';

    /** @var array<class-string> */
    protected static $extra_dataobjects = [DirectGridElementStub::class];

    private const BASE_URL = '/admin/grid/api';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        $memberId = $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        $this->session()->set('loggedInAs', $memberId);
    }

    private function page(): SiteTree
    {
        return $this->objFromFixture(Page::class, 'test_page');
    }

    private function page2(): SiteTree
    {
        return $this->objFromFixture(Page::class, 'test_page_2');
    }

    /**
     * Build a NodeRef payload (`{type, id}`) for a concrete DataObject.
     *
     * @return array{type: string, id: int}
     */
    private function ref(DataObject $object): array
    {
        $type = match (true) {
            $object instanceof Section => 'section',
            $object instanceof Row => 'row',
            $object instanceof Column => 'column',
            $object instanceof SiteTree => 'page',
            default => 'element',
        };

        return ['type' => $type, 'id' => (int) $object->ID];
    }

    /**
     * Synthetic NodeRef payload — used to test non-existent IDs.
     *
     * @return array{type: string, id: int}
     */
    private function syntheticRef(string $type, int $id): array
    {
        return ['type' => $type, 'id' => $id];
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
     * Send a DELETE request with parameters encoded on the query string.
     *
     * DELETE endpoints no longer read from a JSON body — parameters travel
     * on the query string. CSRF token is appended alongside the params.
     *
     * @param array<string, mixed> $params
     */
    private function jsonDelete(string $url, array $params = []): HTTPResponse
    {
        $token = (string) SecurityToken::inst()->getValue();
        $query = http_build_query($params + ['SecurityID' => $token], '', '&', PHP_QUERY_RFC3986);

        $separator = str_contains($url, '?') ? '&' : '?';
        $fullUrl = $url . $separator . $query;

        return Director::test(
            $fullUrl,
            null,
            $this->session(),
            'DELETE',
            null,
            ['Content-Type' => 'application/json'],
        );
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

    public function testReadTreeReturnsTreeStructure(): void
    {
        $this->buildTree();
        $pageId = (int) $this->page()->ID;

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        self::assertArrayHasKey('rootParent', $data);
        self::assertArrayHasKey('nodes', $data);
        $rootParent = (array) $data['rootParent'];
        self::assertSame('page', $rootParent['type']);
        self::assertSame($pageId, $rootParent['id']);
    }

    public function testReadTreeReturns404ForNonExistentPage(): void
    {
        $response = $this->get(self::BASE_URL . '/readTree/999999/main');

        self::assertSame(404, $response->getStatusCode());
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
        self::assertArrayHasKey('rootParent', $data);
        self::assertArrayHasKey('nodes', $data);
    }

    public function testReadTreeAtVersionOneReturns200(): void
    {
        // Requesting version 1 of a page that genuinely has a version 1 must
        // succeed. Pins the `min_range => 1` lower bound on the version
        // filter_var: bumping it to 2 would reject version 1 as out-of-range
        // and surface a 404 instead of this 200.
        $page = $this->page();
        $page->write();
        $pageId = (int) $page->ID;

        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main/version/1");

        self::assertSame(200, $response->getStatusCode());
    }

    public function testReadTreeAtVersionReturnsEmptyTreeForPreSectionVersion(): void
    {
        // Publish v1: page exists, no sections.
        $page = $this->page();
        $page->Title = 'v1 title';
        $page->write();
        $page->publishRecursive();
        $v1 = (int) $page->Version;

        // Sleep past MySQL's DATETIME second boundary. The controller uses
        // Versioned::reading_archived_date($page->LastEdited) which has only
        // second precision, so same-second writes are indistinguishable. A
        // genuine fix for that narrow edge case requires version-pinned
        // element queries against GridElement_Versions — see the comment at
        // GridController::apiReadTreeAtVersion for the known limitation.
        sleep(2);

        // Create a section AFTER v1 was published, modify the page, publish v2.
        GridTreeFactory::section($page, 'main', 0, 'After v1');
        $page = SiteTree::get()->byID((int) $page->ID);
        self::assertNotNull($page);
        $page->Title = 'v2 title';
        $page->write();
        $page->publishRecursive();
        $v2 = (int) $page->Version;
        self::assertGreaterThan($v1, $v2);

        // Request the tree at v1: the section did not exist yet,
        // so it must not appear in the historical snapshot.
        $pageId = (int) $page->ID;
        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/main/version/{$v1}");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);

        self::assertArrayHasKey('nodes', $data);
        self::assertSame(
            [],
            $data['nodes'],
            'At v1 no section had been published yet — tree must be empty.',
        );
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

    #[DataProvider('malformedPageIdUrlProvider')]
    public function testMalformedPageIdSegmentReturns404(string $path): void
    {
        $this->buildTree();

        $response = $this->get(self::BASE_URL . '/' . $path);

        // `$PageID!` only makes the segment mandatory — the router matches any
        // non-empty string. Every route reading it must validate rather than
        // cast, so a malformed id can never resolve to a page.
        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Every route carrying a `$PageID!` segment, crossed with ids the router
     * happily matches but that are not page IDs.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedPageIdUrlProvider(): iterable
    {
        $routes = [
            'readTree' => 'readTree/%s/main',
            'readTreeAtVersion' => 'readTree/%s/main/version/1',
            'acceptableContainers' => 'acceptableContainers/%s/main/column',
            'zones' => 'zones/%s',
        ];

        $malformedIds = [
            'non-numeric' => 'abc',
            'zero' => '0',
            'negative' => '-1',
            'float' => '1.5',
            'digit-prefixed' => '1abc',
        ];

        foreach ($routes as $route => $template) {
            foreach ($malformedIds as $label => $id) {
                yield "{$route} / {$label}" => [sprintf($template, $id)];
            }
        }
    }

    public function testEmptyZoneSegmentReturns404(): void
    {
        $this->buildTree();
        $pageId = (int) $this->page()->ID;

        // HTTPRequest::setUrl() strips the trailing slash BEFORE the extension
        // regex, which then puts one back: `readTree/5/.json` normalises to
        // `readTree/5/` and splits to a trailing '' segment. `isset('')` is
        // true, so `$Zone!` accepts it and the action would otherwise see an
        // empty zone — falsifying the non-empty-string contract. Only reachable
        // where Zone is the LAST route segment, i.e. this one route.
        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}/.json");

        self::assertSame(404, $response->getStatusCode());
    }

    public function testEmptyElementTypeSegmentReturns400(): void
    {
        $pageId = (int) $this->page()->ID;

        // Same empty-final-segment trick as the zone case. ElementType has no
        // guard of its own — '' falls through the match to the 400 below, which
        // is why it is intentionally NOT typed as non-empty-string.
        $response = $this->get(self::BASE_URL . "/acceptableContainers/{$pageId}/main/.json");

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDigitPrefixedPageIdDoesNotResolveToThatPage(): void
    {
        $this->buildTree();
        $pageId = (int) $this->page()->ID;

        // The sharpest edge of casting vs validating: `(int) "12abc"` is 12, so
        // a plain cast would serve page 12's tree under a bogus URL. Asserted
        // against the real fixture id so the leak cannot pass unnoticed.
        $response = $this->get(self::BASE_URL . "/readTree/{$pageId}abc/main");

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCreateSectionReturns204(): void
    {
        $page = $this->page();
        $pageId = (int) $page->ID;

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parent' => $this->ref($page),
            'zone' => 'main',
        ]);

        self::assertSame(204, $response->getStatusCode());

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => Page::class,
        ]);
        self::assertGreaterThanOrEqual(1, $sections->count());
    }

    public function testCreateRowReturns204(): void
    {
        $section = GridTreeFactory::section($this->page());

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'row',
            'parent' => $this->ref($section),
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testCreateColumnReturns204(): void
    {
        $section = GridTreeFactory::section($this->page());
        $row = GridTreeFactory::row($section);

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'column',
            'parent' => $this->ref($row),
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
            'parent' => $this->syntheticRef('page', 999999),
            'zone' => 'main',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateReturns400WhenBothDiscriminatorsPresent(): void
    {
        $page = $this->page();

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'className' => ContentElement::class,
            'parent' => $this->ref($page),
            'zone' => 'main',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateContentReturns204(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->ref($tree['column']),
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * `docs/usage/custom-elements.md` sanctions extending GridElement directly for
     * blocks with no HTML body, and getAllowedTypes() offers such a class in the
     * column's type picker — so the create endpoint must accept what the picker
     * advertised rather than 400 on it.
     */
    public function testCreateContentReturns204ForDirectGridElementSubclass(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => DirectGridElementStub::class,
            'parent' => $this->ref($tree['column']),
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, DirectGridElementStub::get());
    }

    public function testCreateContentReturns400ForNonColumnParent(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->ref($tree['section']),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateContentReturns400ForElementTypedColumnParent(): void
    {
        // The parent NodeRef carries type='element' but a real Column ID.
        // The early `type !== NodeType::Column` guard is the only thing that
        // rejects this: removing it lets findByRef resolve GridElement::byID
        // to the actual Column (Column IS-A GridElement), passing the later
        // `instanceof Column` check and reaching createContentElement (204).
        $tree = $this->buildTree();

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->syntheticRef('element', (int) $tree['column']->ID),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testPublishReturns204(): void
    {
        $tree = $this->buildTree();
        $section = $tree['section'];
        $sectionId = (int) $section->ID;

        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', [
            'element' => $this->ref($section),
            'published' => true,
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
        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', [
            'element' => $this->syntheticRef('section', 999999),
            'published' => true,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testSetPublishedReturns400ForNonBoolPublished(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', [
            'element' => $this->ref($tree['section']),
            'published' => 'yes',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testUnpublishReturns204(): void
    {
        $tree = $this->buildTree();
        $section = $tree['section'];
        $section->publishRecursive();
        $sectionId = (int) $section->ID;

        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', [
            'element' => $this->ref($section),
            'published' => false,
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
        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', [
            'element' => $this->syntheticRef('section', 999999),
            'published' => false,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDeleteReturns204(): void
    {
        $tree = $this->buildTree();
        $contentId = (int) $tree['content']->ID;

        $response = $this->jsonDelete(self::BASE_URL . '/delete', [
            'type' => 'element',
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
            'type' => 'element',
            'id' => 999999,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateReturns204(): void
    {
        $tree = $this->buildTree();

        $sectionCountBefore = Section::get()->filter([
            'ParentID' => (int) $this->page()->ID,
            'ParentClass' => Page::class,
        ])->count();

        $response = $this->jsonPost(self::BASE_URL . '/duplicate', [
            'element' => $this->ref($tree['section']),
        ]);

        self::assertSame(204, $response->getStatusCode());

        $sectionCountAfter = Section::get()->filter([
            'ParentID' => (int) $this->page()->ID,
            'ParentClass' => Page::class,
        ])->count();
        self::assertSame($sectionCountBefore + 1, $sectionCountAfter);
    }

    public function testDuplicateReturns400ForNonExistentElement(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/duplicate', [
            'element' => $this->syntheticRef('section', 999999),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateReturns403WhenParentNoLongerExists(): void
    {
        // The row's source parent (its Section) is deleted out from under it,
        // so Parent()->exists() is false. The duplicate endpoint must refuse
        // with 403 — pins the `!$parent->exists()` arm of the parent guard.
        $tree = $this->buildTree();
        $row = $tree['row'];
        $sectionId = (int) $tree['section']->ID;

        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            "DELETE FROM \"%s\" WHERE \"ID\" = %d",
            $table,
            $sectionId,
        ));

        $response = $this->jsonPost(self::BASE_URL . '/duplicate', [
            'element' => $this->ref($row),
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDuplicateToSectionToOtherPageReturns204(): void
    {
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $page2Id = (int) $page2->ID;

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['section']),
            'targetPageId' => $page2Id,
            'targetZone' => 'main',
            'targetParent' => $this->ref($page2),
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Verify a section now exists under page 2
        $sections = Section::get()->filter([
            'ParentID' => $page2Id,
            'ParentClass' => Page::class,
        ]);
        self::assertGreaterThanOrEqual(1, $sections->count());
    }

    public function testDuplicateToReturns400ForMismatchedTargetParentType(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        // A Row source requires a Section target parent — a Column target
        // parent fails the type check with 400.
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['row']),
            'targetPageId' => $pageId,
            'targetZone' => 'main',
            'targetParent' => $this->ref($tree['column']),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateToReturns400ForInvalidBody(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReorderSameParentSectionsDoesNotTriggerCrossParentCheck(): void
    {
        // Regression: stored Section::ParentClass is the concrete Page class
        // (e.g. "Page"), while the request carries NodeType::Page whose
        // toClass() is SiteTree. A literal string comparison would flag this
        // same-parent reorder as cross-parent. The controller must compare
        // types via NodeType::fromClass so both sides collapse to NodeType::Page.
        $page = $this->page();
        $sectionAlpha = GridTreeFactory::section($page, 'main', 1, 'Alpha');
        $sectionBeta = GridTreeFactory::section($page, 'main', 2, 'Beta');

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($sectionBeta),
            'parent' => $this->ref($page),
            'after' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Beta now has Sort=1, Alpha has Sort=2 — proves the reorder actually
        // took effect (not just that the request was accepted).
        $betaFresh = Section::get()->byID((int) $sectionBeta->ID);
        $alphaFresh = Section::get()->byID((int) $sectionAlpha->ID);
        self::assertNotNull($betaFresh);
        self::assertNotNull($alphaFresh);
        self::assertSame(1, (int) $betaFresh->Sort);
        self::assertSame(2, (int) $alphaFresh->Sort);
    }

    public function testReorderSameParentReturns204(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main', 0, 'Section');
        $row1 = GridTreeFactory::row($section, 1, 'Row 1');
        $row2 = GridTreeFactory::row($section, 2, 'Row 2');

        // Move row2 before row1 (after = null means first position)
        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($row2),
            'parent' => $this->ref($section),
            'after' => null,
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
            'element' => $this->ref($row1),
            'parent' => $this->ref($section2),
            'after' => null,
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

    public function testUpdateGridSettingsReturns204(): void
    {
        $tree = $this->buildTree();
        $column = $tree['column'];
        $columnId = (int) $column->ID;

        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'element' => $this->ref($column),
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // HTTP-level wiring only: whether the update lands as the default or
        // an override is pinned by the settings service/integration layer.
        // Here we confirm the endpoint accepted the update and the column
        // still exists.
        self::assertNotNull(Column::get()->byID($columnId));
    }

    public function testUpdateGridSettingsReturns400ForNonColumn(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'element' => $this->ref($tree['section']),
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

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

        // HTTP-level wiring only: the per-viewport override removal is pinned by
        // the settings service/integration layer. Confirm the column still exists.
        self::assertNotNull(Column::get()->byID((int) $column->ID));
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

        // HTTP-level wiring only: clearing all overrides is pinned by the
        // settings service/integration layer. Confirm the column still exists.
        self::assertNotNull(Column::get()->byID((int) $column->ID));
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

    public function testReorderFindsDraftOnlyElementWhenAmbientStageIsLive(): void
    {
        // Build a DRAFT-only tree (never published), then flip the ambient
        // reading stage to LIVE before dispatching the request. The element
        // exists only on DRAFT, so the controller must find it only if
        // OrmGridElementRepository::findByRef pins the DRAFT stage internally
        // rather than reading the ambient (LIVE) stage. Otherwise the lookup
        // returns null and the endpoint responds 400.
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main', 0, 'Section');
        GridTreeFactory::row($section, 1, 'Row 1');
        $row2 = GridTreeFactory::row($section, 2, 'Row 2');

        Versioned::set_stage(Versioned::LIVE);

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($row2),
            'parent' => $this->ref($section),
            'after' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // The DRAFT-only element was actually moved: row2 now sorts before row1.
        $betaFresh = Versioned::withVersionedMode(static function () use ($row2): ?Row {
            Versioned::set_stage(Versioned::DRAFT);

            return Row::get()->byID((int) $row2->ID);
        });
        self::assertNotNull($betaFresh);
        self::assertSame(1, (int) $betaFresh->Sort);
    }

    /**
     * A mutating request that omits the SecurityID token must be rejected by
     * the CSRF guard in GridController::handleAction() with a 400, before any
     * action runs. Uses a raw Director::test POST (no token appended) rather
     * than the jsonPost helper, which always appends a valid token.
     */
    public function testMutationWithoutSecurityIdReturns400(): void
    {
        $page = $this->page();

        // SecurityToken is disabled by default in the test environment, so the
        // CSRF guard would never fire. Enable it for this test to exercise the
        // rejection path, then restore the prior state.
        $tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::enable();

        try {
            $response = Director::test(
                self::BASE_URL . '/create',
                null,
                $this->session(),
                'POST',
                json_encode([
                    'containerType' => 'section',
                    'parent' => $this->ref($page),
                    'zone' => 'main',
                ], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json'],
            );
        } finally {
            if (!$tokenWasEnabled) {
                SecurityToken::disable();
            }
        }

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * The verb-bound $url_handlers are not the only route to a mutating action:
     * RequestHandler inherits Controller's verb-agnostic '$Action//$ID/$OtherID'
     * rule, which resolves any $allowed_actions entry from the first URL segment.
     * Since apiDelete() reads its input from query vars, a GET to this URL would
     * archive the element with no CSRF check at all if the guard were keyed on
     * the HTTP verb.
     */
    public function testMutationIsNotReachableOverGetWithoutSecurityId(): void
    {
        $tree = $this->buildTree();
        $contentId = (int) $tree['content']->ID;

        $tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::enable();

        try {
            $response = Director::test(
                '/admin/grid/apiDelete?type=element&id=' . $contentId,
                null,
                $this->session(),
                'GET',
            );
        } finally {
            if (!$tokenWasEnabled) {
                SecurityToken::disable();
            }
        }

        self::assertSame(400, $response->getStatusCode());
        self::assertNotNull(ContentElement::get()->byID($contentId));
    }

    public function testCreateContentRejectsUnknownClassNameWith400(): void
    {
        // An unknown / non-ContentElement class name must be rejected at the
        // parser gate (400) and must NOT be autoloaded as a side effect. The
        // chosen name does not correspond to any defined class.
        $tree = $this->buildTree();

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => 'WeDevelop\\Grid\\Tests\\DoesNotExist\\MaliciousClass',
            'parent' => $this->ref($tree['column']),
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse(
            class_exists('WeDevelop\\Grid\\Tests\\DoesNotExist\\MaliciousClass', false),
            'Rejected class name must not have been autoloaded by the validation gate.',
        );
    }

    public function testCreateContentForNonExistentParentReturns400(): void
    {
        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->syntheticRef('column', 999999),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDeleteTouchesOwningPage(): void
    {
        $tree = $this->buildTree();
        $contentId = (int) $tree['content']->ID;
        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($this->page());

        $this->jsonDelete(self::BASE_URL . '/delete', ['type' => 'element', 'id' => $contentId]);

        // Page draft version should have been bumped by touchOwningPage
        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($pageId)->Version);
    }

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

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parent' => $this->ref($restricted['page']),
            'zone' => 'main',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateContentReturns403ForNonEditableParent(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->ref($restricted['column']),
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateReturns403WhenCanCreateDenied(): void
    {
        // Parent page is editable, but Section::canCreate() is vetoed — the new
        // gate must reject with 403 even though the parent canEdit() gate passes.
        Section::add_extension(DenyCreateExtension::class);

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parent' => $this->ref($this->page()),
            'zone' => 'main',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateContentReturns403WhenCanCreateDenied(): void
    {
        // Column parent is editable, but ContentElement::canCreate() is vetoed.
        $tree = $this->buildTree();
        ContentElement::add_extension(DenyCreateExtension::class);

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->ref($tree['column']),
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPublishReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', [
            'element' => $this->ref($restricted['section']),
            'published' => true,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnpublishReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        // Publish the restricted element first so unpublish has something to act
        // on; the canUnpublish() guard must still reject the request with 403.
        $restricted['section']->publishRecursive();

        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', [
            'element' => $this->ref($restricted['section']),
            'published' => false,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUpdateGridSettingsReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'element' => $this->ref($restricted['column']),
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonDelete(self::BASE_URL . '/delete', [
            'type' => 'element',
            'id' => (int) $restricted['content']->ID,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDuplicateReturns403ForNonEditableParent(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPost(self::BASE_URL . '/duplicate', [
            'element' => $this->ref($restricted['section']),
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDuplicateToReturns403ForNonEditableTarget(): void
    {
        // Source on editable page, target on restricted page
        $tree = $this->buildTree();
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['row']),
            'targetPageId' => (int) $restricted['page']->ID,
            'targetZone' => 'main',
            'targetParent' => $this->ref($restricted['section']),
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDuplicateToReturns403ForNonEditableSourcePage(): void
    {
        // Regression for the cross-page content-disclosure gap: the source was gated
        // only by the page-independent canCreate(), so an editor could copy restricted
        // content into their own page and read the clone. Source lives on the restricted
        // test_page, target on the editable page2, so the source's canEdit() guard is
        // what must reject the request — before any target check.
        $restricted = $this->buildRestrictedTree();
        $editablePage = $this->page2();
        $editable = $this->buildTree($editablePage);

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($restricted['row']),
            'targetPageId' => (int) $editablePage->ID,
            'targetZone' => 'main',
            'targetParent' => $this->ref($editable['section']),
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReorderReturns403ForNonEditableElement(): void
    {
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($restricted['row']),
            'parent' => $this->ref($restricted['section']),
            'after' => null,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReorderRejectsNonEditableElementBeforeResolvingTargetParent(): void
    {
        // Non-editable element AND an unresolvable target parent (id 999999).
        // The element canEdit() guard runs first, so the response is 403, not
        // the 400 that an unresolvable target parent would otherwise produce.
        // Pins the ordering: removing the canEdit() guard would let the
        // unresolvable-parent path turn this into a 400.
        $restricted = $this->buildRestrictedTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($restricted['row']),
            'parent' => $this->syntheticRef('section', 999999),
            'after' => null,
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

    public function testReorderReturns400ForNonExistentElement(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->syntheticRef('row', 999999),
            'parent' => $this->ref($tree['section']),
            'after' => null,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReorderReturns400ForNonExistentTargetParent(): void
    {
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($tree['row']),
            'parent' => $this->syntheticRef('section', 999999),
            'after' => null,
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

        // Section's target parent must equal targetPageId, but we provide a different page
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['section']),
            'targetPageId' => (int) $this->page()->ID,
            'targetZone' => 'main',
            'targetParent' => $this->ref($page2),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateToReturns400ForZoneMismatch(): void
    {
        $tree = $this->buildTree();
        $pageId = (int) $this->page()->ID;

        // Row lives in 'main' zone, but we claim target zone is 'sidebar'
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['row']),
            'targetPageId' => $pageId,
            'targetZone' => 'sidebar',
            'targetParent' => $this->ref($tree['section']),
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

        // Row expects a Section target parent — 999999 does not exist
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['row']),
            'targetPageId' => $pageId,
            'targetZone' => 'main',
            'targetParent' => $this->syntheticRef('section', 999999),
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCreateWithInsertAfterElementId(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main', 0, 'Section');
        $row1 = GridTreeFactory::row($section, 1, 'Row 1');
        GridTreeFactory::row($section, 2, 'Row 2');

        // Create a new row inserted after row1 (should end up between row1 and row2)
        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'row',
            'parent' => $this->ref($section),
            'insertAfterElementID' => (int) $row1->ID,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // HTTP-level wiring only: the placement/sort semantics are pinned by the
        // service/integration layer. Here we confirm the request created a row.
        $rows = Row::get()->filter([
            'ParentID' => (int) $section->ID,
            'ParentClass' => Section::class,
        ]);
        self::assertSame(3, $rows->count());
    }

    public function testCreateColumnWithInsertAtStart(): void
    {
        $tree = $this->buildTree();
        $row = $tree['row'];
        GridTreeFactory::column($row, 2);

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'column',
            'parent' => $this->ref($row),
            'insertAtStart' => true,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // HTTP-level wiring only: insert-at-start sort placement is pinned by the
        // service/integration layer. Here we confirm the column was created.
        $columns = Column::get()->filter([
            'ParentID' => (int) $row->ID,
            'ParentClass' => Row::class,
        ]);
        self::assertSame(3, $columns->count());
    }

    public function testCreateRejectsInsertAtStartCombinedWithInsertAfterElementId(): void
    {
        $tree = $this->buildTree();
        $row = $tree['row'];
        $col1 = $tree['column'];

        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'column',
            'parent' => $this->ref($row),
            'insertAfterElementID' => (int) $col1->ID,
            'insertAtStart' => true,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateContentWithInsertAfterElementId(): void
    {
        $tree = $this->buildTree();
        $column = $tree['column'];
        $content1 = $tree['content'];
        GridTreeFactory::contentElement($column, 2, 'Content 2');

        // Create new content element inserted after content1
        $response = $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->ref($column),
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

        // Update 'lg' to match the default values (width=12, offset=0, visible=true)
        // This should remove the override since it's now redundant
        $response = $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'element' => $this->ref($column),
            'viewport' => 'lg',
            'width' => 12,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertSame(204, $response->getStatusCode());
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

        // Page 2 has no sections — the descendants scan yields no columns
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
        GridTreeFactory::column($row, 0, $settings);

        // Reset 'xl' viewport — column only has 'lg', so it should be skipped
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => (int) $page->ID,
            'zone' => 'main',
            'viewport' => 'xl',
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

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
            'element' => $this->ref($sourceTree['row']),
            'parent' => $this->ref($restricted['section']),
            'after' => null,
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
        $row = $tree['row'];
        $sectionId = (int) $tree['section']->ID;

        // Orphan the row by removing its source parent via raw SQL
        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            "DELETE FROM \"%s\" WHERE \"ID\" = %d",
            $table,
            $sectionId,
        ));

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($row),
            'parent' => $this->ref($targetTree['section']),
            'after' => null,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReorderResultErrorPropagates(): void
    {
        // Move a Column into a Section — Section only allows Rows, so
        // ReorderValidator returns Result::fail which surfaces as 422
        $tree = $this->buildTree();

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($tree['column']),
            'parent' => $this->ref($tree['section']),
            'after' => null,
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testResetGridSettingsOverridesWithNoRows(): void
    {
        // Section exists but has no rows (auto_scaffold is disabled in setUp),
        // so the descendants scan yields no columns
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

        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            "UPDATE \"%s\" SET \"ParentID\" = 0 WHERE \"ID\" = %d",
            $table,
            $contentId,
        ));

        $response = $this->jsonDelete(self::BASE_URL . '/delete', [
            'type' => 'element',
            'id' => $contentId,
        ]);

        // touchOwningPage receives null page and returns early — no crash
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPublishReturns400ForMissingElement(): void
    {
        // Body without 'published' — non-bool published → jsonError(400)
        $response = $this->jsonPatch(self::BASE_URL . '/setPublished', []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReorderSectionUsesPageLookup(): void
    {
        // Reorder a section from page1 to page2 — exercises resolveNodeRef
        // routing the Page NodeType to a SiteTree table query.
        $page1 = $this->page();
        $page2 = $this->page2();
        $section = GridTreeFactory::section($page1, 'main', 0, 'Movable Section');

        $response = $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($section),
            'parent' => $this->ref($page2),
            'after' => null,
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

        // Row's target parent is a valid section on page1, but targetPageId
        // points to page2 — owningPage.ID !== targetPageId → 400
        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['row']),
            'targetPageId' => (int) $page2->ID,
            'targetZone' => 'main',
            'targetParent' => $this->ref($tree['section']),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateSectionSetsZone(): void
    {
        $page = $this->page();
        $pageId = (int) $page->ID;

        $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parent' => $this->ref($page),
            'zone' => 'sidebar',
        ]);

        $section = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => Page::class,
            'Zone' => 'sidebar',
        ])->first();
        self::assertNotNull($section, 'Section should have Zone set to "sidebar"');
    }

    public function testCreateRowDoesNotSetZone(): void
    {
        $section = GridTreeFactory::section($this->page(), 'main');

        $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'row',
            'parent' => $this->ref($section),
        ]);

        $rows = Row::get()->filter([
            'ParentID' => (int) $section->ID,
            'ParentClass' => Section::class,
        ]);
        foreach ($rows as $row) {
            self::assertEmpty($row->Zone, 'Row should not have Zone set');
        }
    }

    public function testCreateSectionTouchesOwningPage(): void
    {
        $page = $this->page();
        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($page);

        $this->jsonPost(self::BASE_URL . '/create', [
            'containerType' => 'section',
            'parent' => $this->ref($page),
            'zone' => 'main',
        ]);

        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($pageId)->Version);
    }

    public function testDuplicateToCreatesDeepCopy(): void
    {
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $page2Id = (int) $page2->ID;

        // Duplicate section (with its row+column+content) to page2
        $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['section']),
            'targetPageId' => $page2Id,
            'targetZone' => 'main',
            'targetParent' => $this->ref($page2),
        ]);

        // Verify section created under page2
        $newSection = Section::get()->filter([
            'ParentID' => $page2Id,
            'ParentClass' => Page::class,
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
            'element' => $this->ref($tree['section']),
            'targetPageId' => $page2Id,
            'targetZone' => 'sidebar',
            'targetParent' => $this->ref($page2),
        ]);

        $newSection = Section::get()->filter([
            'ParentID' => $page2Id,
            'ParentClass' => Page::class,
            'Zone' => 'sidebar',
        ])->first();
        self::assertNotNull($newSection, 'Duplicated section should have target zone "sidebar"');
    }

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

    public function testResetOverridesAffectsMultipleColumns(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);

        // Create 3 columns with different viewport overrides so the reset affects > 0 columns
        GridTreeFactory::column($row, 1, new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(6, 0, true)],
        ));
        GridTreeFactory::column($row, 2, new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(4, 0, true)],
        ));
        GridTreeFactory::column($row, 3, new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(8, 0, true)],
        ));

        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($page);

        // Reset ALL overrides for the page
        $response = $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => $pageId,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertSame(204, $response->getStatusCode());

        // Page should be touched since affected > 0
        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($pageId)->Version);
    }

    public function testPagesReturnsMultipleResults(): void
    {
        $response = $this->get(self::BASE_URL . '/pages');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->parseJson($response);
        // Fixture has 2 pages, both should be editable
        self::assertGreaterThanOrEqual(2, count($data));
    }

    /**
     * Publish the page and capture the LIVE version. Callers run a write
     * action and assert the DRAFT stage Version is higher — proving the
     * endpoint's `$this->touchOwningPage(...)` call fired, which pins its
     * MethodCallRemoval mutant.
     *
     * @return array{int, int} [liveVersion, pageId]
     */
    private function publishAndCaptureLiveVersion(SiteTree $page): array
    {
        $page->publishRecursive();
        $live = (int) Versioned::withVersionedMode(static function () use ($page): int {
            Versioned::set_stage(Versioned::LIVE);

            return (int) SiteTree::get()->byID($page->ID)->Version;
        });

        return [$live, (int) $page->ID];
    }

    public function testCreateContentTouchesOwningPage(): void
    {
        $tree = $this->buildTree();
        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($this->page());

        $this->jsonPost(self::BASE_URL . '/create', [
            'className' => ContentElement::class,
            'parent' => $this->ref($tree['column']),
        ]);

        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($pageId)->Version);
    }

    public function testDuplicateTouchesOwningPage(): void
    {
        $tree = $this->buildTree();
        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($this->page());

        $this->jsonPost(self::BASE_URL . '/duplicate', [
            'element' => $this->ref($tree['section']),
        ]);

        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($pageId)->Version);
    }

    public function testDuplicateToTouchesOwningPage(): void
    {
        $tree = $this->buildTree();
        $page2 = $this->page2();
        [$liveVersion, $page2Id] = $this->publishAndCaptureLiveVersion($page2);

        $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['section']),
            'targetPageId' => $page2Id,
            'targetZone' => 'main',
            'targetParent' => $this->ref($page2),
        ]);

        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($page2Id)->Version);
    }

    public function testReorderTouchesOwningPage(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main', 0, 'Section');
        $row1 = GridTreeFactory::row($section, 1, 'Row 1');
        $row2 = GridTreeFactory::row($section, 2, 'Row 2');
        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($page);

        $this->jsonPatch(self::BASE_URL . '/reorder', [
            'element' => $this->ref($row2),
            'parent' => $this->ref($section),
            'after' => null,
        ]);

        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($pageId)->Version);
    }

    public function testUpdateGridSettingsTouchesOwningPage(): void
    {
        $tree = $this->buildTree();
        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($this->page());

        $this->jsonPatch(self::BASE_URL . '/updateGridSettings', [
            'element' => $this->ref($tree['column']),
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertGreaterThan($liveVersion, (int) SiteTree::get()->byID($pageId)->Version);
    }

    public function testResetOverridesDoesNotTouchPageWhenZeroChanged(): void
    {
        // Column has no overrides — resetOverrides returns 0 affected.
        // Pins `if ($result->unwrap() > 0)`: mutated to `>= 0` would touch the
        // page even when nothing changed.
        $page = $this->page();
        $section = GridTreeFactory::section($page, 'main');
        $row = GridTreeFactory::row($section);
        GridTreeFactory::column($row);

        [$liveVersion, $pageId] = $this->publishAndCaptureLiveVersion($page);
        $draftVersionBefore = (int) SiteTree::get()->byID($pageId)->Version;

        $this->jsonDelete(self::BASE_URL . '/resetGridSettingsOverrides', [
            'pageId' => $pageId,
            'zone' => 'main',
            'viewport' => null,
        ]);

        // No change — draft version must equal the pre-request version
        self::assertSame($draftVersionBefore, (int) SiteTree::get()->byID($pageId)->Version);
        // And still above LIVE from the publish, so we know the publish itself worked
        self::assertGreaterThanOrEqual($liveVersion, $draftVersionBefore);
    }

    public function testDuplicateToRowIntoSectionReturns204(): void
    {
        // Row source → Section target. Pins the `Row => Section` arm of
        // NodeType::expectedParentType(); a wrong arm makes the type check
        // reject a legitimate duplicate with 400.
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $targetSection = GridTreeFactory::section($page2, 'main');

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['row']),
            'targetPageId' => (int) $page2->ID,
            'targetZone' => 'main',
            'targetParent' => $this->ref($targetSection),
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDuplicateToColumnIntoRowReturns204(): void
    {
        // Column source → Row target. Pins the `Column => Row` match arm.
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $targetSection = GridTreeFactory::section($page2, 'main');
        $targetRow = GridTreeFactory::row($targetSection);

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['column']),
            'targetPageId' => (int) $page2->ID,
            'targetZone' => 'main',
            'targetParent' => $this->ref($targetRow),
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDuplicateToElementIntoColumnReturns204(): void
    {
        // Element source → Column target. Pins the `Element => Column` match arm.
        $tree = $this->buildTree();
        $page2 = $this->page2();
        $targetSection = GridTreeFactory::section($page2, 'main');
        $targetRow = GridTreeFactory::row($targetSection);
        $targetColumn = GridTreeFactory::column($targetRow);

        $response = $this->jsonPost(self::BASE_URL . '/duplicateTo', [
            'element' => $this->ref($tree['content']),
            'targetPageId' => (int) $page2->ID,
            'targetZone' => 'main',
            'targetParent' => $this->ref($targetColumn),
        ]);

        self::assertSame(204, $response->getStatusCode());
    }
}
