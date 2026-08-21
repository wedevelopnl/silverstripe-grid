<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Controllers;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Admin\SharedBlockAdmin;
use WeDevelop\Grid\Controllers\GridController;
use WeDevelop\Grid\Controllers\SharedBlockController;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\VetoBlockViewExtension;

#[CoversClass(SharedBlockController::class)]
#[CoversClass(GridController::class)]
final class SharedBlockControllerTest extends FunctionalTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../../Integration/Fixture/page.yml';

    private const BASE_URL = '/admin/grid-shared-blocks/api';

    /**
     * The block library's own controller does not serve page content, so the
     * few cases below that seed or read page-side state address the grid
     * controller instead.
     */
    private const GRID_BASE_URL = '/admin/grid/api';

    private const LIBRARY_PERMISSION = 'CMS_ACCESS_' . SharedBlockAdmin::class;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        $this->logInAsLibraryManager();
    }

    /**
     * CMS_ACCESS_LeftAndMain is the framework's "all CMS areas" code — it
     * satisfies any CMS_ACCESS_* check, the library's included.
     */
    private function logInAsLibraryManager(): void
    {
        $memberId = $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        $this->session()->set('loggedInAs', $memberId);
    }

    /**
     * A member who may fully edit pages but was never granted the block
     * library.
     *
     * Deliberately NOT CMS_ACCESS_LeftAndMain, which implies every section, and
     * deliberately paired with SITETREE_EDIT_ALL so a refusal below can only
     * come from the library gate and never from page permissions.
     */
    private function logInAsPageEditorOnly(): void
    {
        $memberId = $this->logInWithPermission(['CMS_ACCESS_CMSMain', 'SITETREE_EDIT_ALL']);
        $this->session()->set('loggedInAs', $memberId);
    }

    private function page(): SiteTree
    {
        return $this->objFromFixture(Page::class, 'test_page');
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(string $method, string $url, array $body = [], bool $withToken = true): HTTPResponse
    {
        $fullUrl = $url;

        if ($withToken) {
            $token = SecurityToken::inst()->getValue();
            $fullUrl .= (str_contains($url, '?') ? '&' : '?') . 'SecurityID=' . $token;
        }

        return Director::test(
            $fullUrl,
            null,
            $this->session(),
            $method,
            json_encode($body, JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json'],
        );
    }

    /** @return array<mixed> */
    private function parseJson(HTTPResponse $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array{type: string, id: int} */
    private function ref(DataObject $object): array
    {
        $type = match (true) {
            $object instanceof SharedBlock => 'sharedBlock',
            $object instanceof Section => 'section',
            $object instanceof SiteTree => 'page',
            default => 'element',
        };

        return ['type' => $type, 'id' => (int) $object->ID];
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

    private function leafRootedBlock(string $title = 'Leaf block'): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock($title);

        $leaf = ContentElement::create();
        $leaf->Title = 'Shared paragraph';
        $leaf->ParentID = $block->ID;
        $leaf->ParentClass = SharedBlock::class;
        $leaf->write();

        return $block;
    }

    public function testSharedBlocksListReturnsEveryBlockWhenUnfiltered(): void
    {
        $this->sectionRootedBlock('Section block');
        $this->leafRootedBlock('Leaf block');

        $response = $this->jsonRequest('GET', self::BASE_URL . '/list');

        self::assertSame(200, $response->getStatusCode());
        $entries = $this->parseJson($response);

        self::assertCount(2, $entries);
        self::assertSame(
            ['Leaf block', 'Section block'],
            array_map(static fn (array $e): string => $e['title'], $entries),
        );
    }

    public function testSharedBlocksListFiltersByParentType(): void
    {
        $this->sectionRootedBlock('Section block');
        $this->leafRootedBlock('Leaf block');

        $pageLevel = $this->parseJson($this->jsonRequest('GET', self::BASE_URL . '/list?parentType=page'));
        self::assertCount(1, $pageLevel);
        self::assertSame('Section block', $pageLevel[0]['title']);
        self::assertSame('section', $pageLevel[0]['rootType']);
        self::assertSame(0, $pageLevel[0]['usageCount']);
        self::assertSame('notPublished', $pageLevel[0]['status']);

        $columnLevel = $this->parseJson($this->jsonRequest('GET', self::BASE_URL . '/list?parentType=column'));
        self::assertCount(1, $columnLevel);
        self::assertSame('Leaf block', $columnLevel[0]['title']);
        self::assertSame('element', $columnLevel[0]['rootType']);
    }

    public function testPlaceEndpointCreatesReference(): void
    {
        $page = $this->page();
        $block = $this->sectionRootedBlock();

        $response = $this->jsonRequest('POST', self::BASE_URL . '/place', [
            'blockId' => (int) $block->ID,
            'parent' => $this->ref($page),
            'zone' => 'main',
        ]);

        self::assertSame(204, $response->getStatusCode());

        $reference = SharedBlockReference::get()->filter(['BlockID' => $block->ID])->first();
        self::assertInstanceOf(SharedBlockReference::class, $reference);
        self::assertSame('main', (string) $reference->Zone);
    }

    public function testPlaceEndpointRejectsUnknownBlock(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/place', [
            'blockId' => 999999,
            'parent' => $this->ref($this->page()),
            'zone' => 'main',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPlaceEndpointRejectsIncompatibleRoot(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/place', [
            'blockId' => (int) $this->leafRootedBlock()->ID,
            'parent' => $this->ref($this->page()),
            'zone' => 'main',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPlaceEndpointRequiresCsrfToken(): void
    {
        // SecurityToken is disabled by default in the test environment, so the
        // CSRF guard would never fire. Enable it for this test, then restore.
        $body = ['blockId' => (int) $this->sectionRootedBlock()->ID, 'parent' => $this->ref($this->page())];

        $tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::enable();

        try {
            $response = $this->jsonRequest('POST', self::BASE_URL . '/place', $body, withToken: false);
        } finally {
            if (!$tokenWasEnabled) {
                SecurityToken::disable();
            }
        }

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReadSharedBlockTreeNeedsNoCsrfToken(): void
    {
        // Read-only actions are exempt from the token gate.
        $block = $this->sectionRootedBlock();

        $tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::enable();

        try {
            $response = $this->jsonRequest(
                'GET',
                self::BASE_URL . '/readTree/' . $block->ID,
                withToken: false,
            );
        } finally {
            if (!$tokenWasEnabled) {
                SecurityToken::disable();
            }
        }

        self::assertSame(200, $response->getStatusCode());
    }

    public function testConvertEndpointReturnsBlockId(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, zone: 'main', title: 'Promote me');

        $response = $this->jsonRequest('POST', self::BASE_URL . '/convert', [
            'element' => $this->ref($section),
            'title' => 'Promoted block',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->parseJson($response);

        self::assertArrayHasKey('blockId', $body);
        $block = SharedBlock::get()->byID($body['blockId']);
        self::assertInstanceOf(SharedBlock::class, $block);
        self::assertSame('Promoted block', $block->Title);
    }

    public function testConvertEndpointDefaultsTitleToTheElement(): void
    {
        $section = GridTreeFactory::section($this->page(), zone: 'main', title: 'Hero banner');

        $body = $this->parseJson($this->jsonRequest('POST', self::BASE_URL . '/convert', [
            'element' => $this->ref($section),
        ]));

        $block = SharedBlock::get()->byID($body['blockId']);
        self::assertInstanceOf(SharedBlock::class, $block);
        self::assertSame('Hero banner', $block->Title);
    }

    public function testDetachEndpointRejectsNonReference(): void
    {
        $section = GridTreeFactory::section($this->page(), zone: 'main');

        $response = $this->jsonRequest('POST', self::BASE_URL . '/detach', [
            'element' => $this->ref($section),
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDetachEndpointReplacesReferenceWithACopy(): void
    {
        $page = $this->page();
        $reference = GridTreeFactory::reference($page, $this->sectionRootedBlock());
        $referenceId = (int) $reference->ID;

        $response = $this->jsonRequest('POST', self::BASE_URL . '/detach', [
            'element' => $this->ref($reference),
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertNull(SharedBlockReference::get()->byID($referenceId));
        self::assertSame(
            1,
            Section::get()->filter(['ParentID' => $page->ID, 'ParentClass' => $page::class])->count(),
        );
    }

    public function testSetPublishedEndpointPublishesTheBlock(): void
    {
        $block = $this->sectionRootedBlock();

        $response = $this->jsonRequest('PATCH', self::BASE_URL . '/setPublished', [
            'blockId' => (int) $block->ID,
            'published' => true,
        ]);

        self::assertSame(204, $response->getStatusCode());

        Versioned::set_stage(Versioned::LIVE);
        self::assertNotNull(SharedBlock::get()->byID($block->ID));
        self::assertSame(1, Section::get()->filter(['ParentClass' => SharedBlock::class])->count());
    }

    public function testSetPublishedEndpointRejectsNonBooleanFlag(): void
    {
        $response = $this->jsonRequest('PATCH', self::BASE_URL . '/setPublished', [
            'blockId' => (int) $this->sectionRootedBlock()->ID,
            'published' => 'yes',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testReadSharedBlockTreeReturnsBlockRootedTree(): void
    {
        $block = $this->sectionRootedBlock();

        $response = $this->jsonRequest('GET', self::BASE_URL . '/readTree/' . $block->ID);

        self::assertSame(200, $response->getStatusCode());
        $tree = $this->parseJson($response);

        self::assertSame(['type' => 'sharedBlock', 'id' => (int) $block->ID], $tree['rootParent']);
        self::assertCount(1, $tree['nodes']);
        self::assertSame('Shared section', $tree['nodes'][0]['title']);
    }

    public function testReadSharedBlockTreeRejectsNonNumericId(): void
    {
        $response = $this->jsonRequest('GET', self::BASE_URL . '/readTree/abc');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReadSharedBlockTreeRejectsUnknownBlock(): void
    {
        $response = $this->jsonRequest('GET', self::BASE_URL . '/readTree/999999');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTheGridPageTreeRouteStillResolvesBesideTheBlockController(): void
    {
        // Both controllers now expose an `api/readTree` action — one keyed by
        // page + zone, one by block id — kept apart only by the admin URL
        // segment they are mounted under. Pin that the grid's own route is
        // still reachable, since a segment clash would silently reroute it.
        $page = $this->page();
        GridTreeFactory::section($page, zone: 'main', title: 'Local');

        $response = $this->jsonRequest(
            'GET',
            self::GRID_BASE_URL . '/readTree/' . $page->ID . '/main',
        );

        self::assertSame(200, $response->getStatusCode());
        $tree = $this->parseJson($response);
        self::assertSame(['type' => 'page', 'id' => (int) $page->ID], $tree['rootParent']);
    }

    public function testCreateEndpointSeedsABlockRoot(): void
    {
        // The library editor's root add: a brand-new block gets its first element.
        $block = GridTreeFactory::sharedBlock('Empty block');

        $response = $this->jsonRequest('POST', self::GRID_BASE_URL . '/create', [
            'containerType' => 'section',
            'parent' => $this->ref($block),
            'zone' => 'main',
        ]);

        self::assertSame(204, $response->getStatusCode());

        $root = $block->getRootElement();
        self::assertInstanceOf(Section::class, $root);
        self::assertSame('', (string) $root->Zone, 'a block root belongs to no zone');
    }

    public function testCreateEndpointRejectsAReferenceClassName(): void
    {
        ['column' => $column] = GridTreeFactory::containerTree($this->page());

        $response = $this->jsonRequest('POST', self::GRID_BASE_URL . '/create', [
            'className' => SharedBlockReference::class,
            'parent' => ['type' => 'column', 'id' => (int) $column->ID],
        ]);

        self::assertSame(400, $response->getStatusCode());
    }











    // --- Permission gates -------------------------------------------------
    //
    // Every mutating endpoint below is guarded by one canX() call and nothing
    // else. Without these the guards could all be deleted and the suite would
    // stay green, which is how a page-only editor came to be able to edit and
    // delete library records through the API.

    public function testConvertIsRefusedWithoutLibraryAccess(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, zone: 'main', title: 'Local section');

        $this->logInAsPageEditorOnly();

        $response = $this->jsonRequest('POST', self::BASE_URL . '/convert', [
            'element' => $this->ref($section),
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }


    public function testSetPublishedIsRefusedWithoutLibraryAccess(): void
    {
        $block = $this->sectionRootedBlock();

        $this->logInAsPageEditorOnly();

        $response = $this->jsonRequest('PATCH', self::BASE_URL . '/setPublished', [
            'blockId' => (int) $block->ID,
            'published' => true,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPlaceIsRefusedWhenThePageMayNotBeEdited(): void
    {
        $block = $this->sectionRootedBlock();
        $page = $this->page();

        // Library access but no page rights: the mirror image of the tests
        // above, pinning the OTHER guard on this endpoint.
        $memberId = $this->logInWithPermission(self::LIBRARY_PERMISSION);
        $this->session()->set('loggedInAs', $memberId);
        $page->CanEditType = 'OnlyTheseUsers';
        $page->write();

        $response = $this->jsonRequest('POST', self::BASE_URL . '/place', [
            'blockId' => (int) $block->ID,
            'parent' => $this->ref($page),
            'zone' => 'main',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, SharedBlockReference::get(), 'no placement may be written');
    }

    public function testPlaceIsRefusedForABlockTheAuthorMayNotView(): void
    {
        // The picker filters on canView(), but that is UI: a direct call carries
        // an arbitrary blockId, so the endpoint has to apply the same gate.
        SharedBlock::add_extension(VetoBlockViewExtension::class);
        $block = $this->sectionRootedBlock();

        $response = $this->jsonRequest('POST', self::BASE_URL . '/place', [
            'blockId' => (int) $block->ID,
            'parent' => $this->ref($this->page()),
            'zone' => 'main',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, SharedBlockReference::get(), 'no placement may be written');
    }

    public function testReadTreeIsRefusedForABlockTheAuthorMayNotView(): void
    {
        SharedBlock::add_extension(VetoBlockViewExtension::class);
        $block = $this->sectionRootedBlock();

        $response = $this->jsonRequest(
            'GET',
            self::BASE_URL . '/readTree/' . (int) $block->ID,
        );

        self::assertSame(403, $response->getStatusCode());
    }


    public function testBlockListOmitsBlocksTheAuthorMayNotView(): void
    {
        SharedBlock::add_extension(VetoBlockViewExtension::class);
        $this->sectionRootedBlock('Hidden block');

        $response = $this->jsonRequest('GET', self::BASE_URL . '/list');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->parseJson($response));
    }

    public function testCreateSeedsABlockWithTheRequestedContainerRoot(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/create', [
            'containerType' => 'section',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->parseJson($response);
        $block = SharedBlock::get()->byID($body['id']);

        self::assertNotNull($block);
        self::assertInstanceOf(Section::class, $block->getRootElement());
    }

    /**
     * The response is what the add button navigates to, so the link has to
     * address this block's own form rather than the library listing.
     */
    public function testCreateReturnsTheNewBlocksEditLink(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/create', [
            'containerType' => 'section',
        ]);

        $body = $this->parseJson($response);

        self::assertSame(
            SharedBlock::get()->byID($body['id'])?->getCMSEditLink(),
            $body['editLink'],
        );
    }

    public function testCreateSeedsABlockWithALeafRoot(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/create', [
            'className' => ContentElement::class,
        ]);

        self::assertSame(200, $response->getStatusCode());

        $block = SharedBlock::get()->byID($this->parseJson($response)['id']);

        self::assertInstanceOf(ContentElement::class, $block?->getRootElement());
    }

    public function testCreateRejectsABodyNamingBothARootTypeAndAClass(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/create', [
            'containerType' => 'section',
            'className' => ContentElement::class,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    public function testCreateRejectsABodyNamingNoRootAtAll(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/create', []);

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    public function testCreateRejectsAClassAColumnCannotHold(): void
    {
        $response = $this->jsonRequest('POST', self::BASE_URL . '/create', [
            'className' => SharedBlockReference::class,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    public function testCreateIsRefusedWithoutLibraryAccess(): void
    {
        $this->logInAsPageEditorOnly();

        $response = $this->jsonRequest('POST', self::BASE_URL . '/create', [
            'containerType' => 'section',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    public function testCreateEndpointRequiresCsrfToken(): void
    {
        $tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::enable();

        try {
            $response = $this->jsonRequest(
                'POST',
                self::BASE_URL . '/create',
                ['containerType' => 'section'],
                withToken: false,
            );
        } finally {
            if (!$tokenWasEnabled) {
                SecurityToken::disable();
            }
        }

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }
}
