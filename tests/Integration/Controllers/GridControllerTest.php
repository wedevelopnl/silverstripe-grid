<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Controllers\GridController;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Extensions\GridPageExtension;
use SilverStripe\Control\HTTPResponse;
use WeDevelop\Grid\Service\GridTreeBuilder;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;

#[CoversClass(GridController::class)]
final class GridControllerTest extends FunctionalTest
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

    public function onBeforeLoadFixtures(): void
    {
        parent::onBeforeLoadFixtures();
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    private function apiUrl(int $pageId, string $zone = 'main'): string
    {
        return '/admin/grid/api/readTree/' . $pageId . '/' . $zone;
    }

    /**
     * Authenticate via the FunctionalTest HTTP session (not just the in-memory identity store).
     *
     * SapphireTest::logInWithPermission() writes to the current Controller's session,
     * which is a different Session object than FunctionalTest::mainSession. Admin routes
     * authenticate via the HTTP session, so we must set 'loggedInAs' on the test session.
     *
     * Uses CMS_ACCESS_LeftAndMain because SilverStripe's Permission::checkMember() treats
     * 'CMS_ACCESS' as a category check — it matches CMS_ACCESS_* codes, not a literal
     * 'CMS_ACCESS' permission record.
     */
    private function logInForHttp(string $permissionCode = 'CMS_ACCESS_LeftAndMain'): void
    {
        $memberId = $this->logInWithPermission($permissionCode);
        $this->session()->set('loggedInAs', $memberId);
    }

    /**
     * Assert that a response is a JSON error with the expected status code and message.
     */
    private function assertJsonError(int $expectedCode, string $expectedMessage, mixed $response): void
    {
        $this->assertSame($expectedCode, $response->getStatusCode());

        $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('error', $body['status']);
        $this->assertCount(1, $body['errors']);
        $this->assertSame('error', $body['errors'][0]['type']);
        $this->assertSame($expectedCode, $body['errors'][0]['code']);
        $this->assertSame($expectedMessage, $body['errors'][0]['value']);
    }

    public function testReadTreeReturnsJsonForValidPage(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $response = $this->get($this->apiUrl($page->ID));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));

        $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        // Response is keyed by area ID (numeric), not relation name
        $areaKeys = array_keys($body);
        $this->assertNotEmpty($areaKeys);
        $this->assertIsInt($areaKeys[0]);

        $firstArea = $body[$areaKeys[0]];
        $this->assertNotEmpty($firstArea);

        // Verify nested structure exists
        $firstSection = $firstArea[0];
        $this->assertSame('First Section', $firstSection['title']);
        $this->assertArrayHasKey('children', $firstSection);
    }

    public function testReadTreeReturns404ForMissingPage(): void
    {
        $this->logInForHttp();

        $response = $this->get($this->apiUrl(999999));

        $this->assertJsonError(
            404,
            "Sorry, it seems you were trying to access a section or object that doesn't exist.",
            $response,
        );
    }

    public function testReadTreeReturns403WithoutPermission(): void
    {
        $page = $this->objFromFixture(TestPage::class, 'testpage');

        // Not logged in — AdminController redirects to login or returns 403
        $this->autoFollowRedirection = false;
        $response = $this->get($this->apiUrl($page->ID));

        // AdminController handles unauthenticated access before our action runs.
        // Depending on context it returns 302 (redirect to login) or 403.
        $this->assertTrue(
            \in_array($response->getStatusCode(), [302, 403], true),
            'Expected 302 or 403, got ' . $response->getStatusCode(),
        );
    }

    public function testReadTreeReturnsEmptyTreeForPageWithoutElements(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = SiteTree::create();
        $page->Title = 'Non-Grid Page';
        $page->write();

        $response = $this->get($this->apiUrl($page->ID));

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        $pageId = (int) $page->ID;
        $this->assertArrayHasKey($pageId, $body);
        $this->assertSame([], $body[$pageId]);
    }

    public function testReadTreeFindsPageRegardlessOfAmbientStage(): void
    {
        $this->logInForHttp();

        // Load page ID while in DRAFT (the page is only on draft stage)
        $pageId = Versioned::withVersionedMode(function (): int {
            Versioned::set_stage(Versioned::DRAFT);

            return $this->objFromFixture(TestPage::class, 'testpage')->ID;
        });

        // Set ambient stage to LIVE — the controller must internally switch to DRAFT
        Versioned::set_stage(Versioned::LIVE);

        $response = $this->get($this->apiUrl($pageId));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * POST a JSON body to an API endpoint with security token disabled.
     *
     * @param array<string, mixed> $body
     */
    private function postJson(string $url, array $body): mixed
    {
        SecurityToken::disable();

        try {
            return $this->post(
                $url,
                data: null,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($body, JSON_THROW_ON_ERROR),
            );
        } finally {
            SecurityToken::enable();
        }
    }

    // --- apiCreate: validation ------------------------------------------------

    public function testCreateAcceptsAfterElementIdOne(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $section = $this->objFromFixture(Section::class, 'section1');

        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'section',
            'parentId' => (int) $page->ID,
            'insertAfterElementID' => $section->ID,
        ]);

        // Passes body validation and creates successfully
        $this->assertSame(204, $response->getStatusCode());
    }

    // --- apiCreate: validation fails -----------------------------------------

    public function testCreateReturns422WhenValidationFails(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section = $this->objFromFixture(Section::class, 'section1');

        // Section only allows Row — placing a Column directly in a Section violates the hierarchy
        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'column',
            'parentId' => (int) $section->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertJsonError(422, 'Column cannot be placed inside Section.', $response);
    }

    // --- apiDuplicate --------------------------------------------------------

    public function testDuplicateAssignsCorrectCopyTitle(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section = $this->objFromFixture(Section::class, 'section1');

        $response = $this->postJson('/admin/grid/api/duplicate', [
            'id' => $section->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Find the cloned element — highest ID section
        $clone = Section::get()->sort('ID', 'DESC')->first();
        $this->assertSame('First Section copy', $clone->Title);
    }

    public function testDuplicateIncrementsCopyNumber(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        // Create a section titled "Block copy" to trigger the copy-number path
        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $section = Section::create();
        $section->Title = 'Block copy';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $response = $this->postJson('/admin/grid/api/duplicate', [
            'id' => $section->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $clone = Section::get()->sort('ID', 'DESC')->first();
        $this->assertSame('Block copy 2', $clone->Title);
    }

    public function testDuplicateIncrementsExistingCopyNumber(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $section = Section::create();
        $section->Title = 'Block copy 3';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $response = $this->postJson('/admin/grid/api/duplicate', [
            'id' => $section->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $clone = Section::get()->sort('ID', 'DESC')->first();
        $this->assertSame('Block copy 4', $clone->Title);
    }

    public function testDuplicateReturns422WhenValidationFails(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');

        // Create a Row directly in the page area, bypassing validation
        // so we have an "invalid" record to duplicate via the API
        $row = Row::create();
        $row->Title = 'Invalid Row';
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;
        $row->write(skipValidation: true);

        $response = $this->postJson('/admin/grid/api/duplicate', [
            'id' => $row->ID,
        ]);

        $this->assertJsonError(422, 'Row cannot be placed at page level.', $response);
    }


    /**
     * PATCH a JSON body to an API endpoint with security token disabled.
     *
     * FunctionalTest has no patch() method, so we use TestSession::sendRequest() directly.
     *
     * @param array<string, mixed> $body
     */
    private function patchJson(string $url, array $body): HTTPResponse
    {
        SecurityToken::disable();

        try {
            return $this->mainSession->sendRequest(
                'PATCH',
                $url,
                data: [],
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($body, JSON_THROW_ON_ERROR),
            );
        } finally {
            SecurityToken::enable();
        }
    }

    /**
     * DELETE a JSON body to an API endpoint with security token disabled.
     *
     * @param array<string, mixed> $body
     */
    private function deleteJson(string $url, array $body): HTTPResponse
    {
        SecurityToken::disable();

        try {
            return $this->mainSession->sendRequest(
                'DELETE',
                $url,
                data: [],
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($body, JSON_THROW_ON_ERROR),
            );
        } finally {
            SecurityToken::enable();
        }
    }

    // --- apiReorder: cross-zone enforcement -----------------------------------

    public function testReorderRejects422ForCrossZoneSectionMove(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $section1 = $this->objFromFixture(Section::class, 'section1');
        $sidebarSection1 = $this->objFromFixture(Section::class, 'sidebar_section1');

        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $section1->ID,
            'targetParentId' => (int) $page->ID,
            'afterElementID' => $sidebarSection1->ID,
        ]);

        $this->assertJsonError(
            422,
            'The reference element no longer exists in the target parent.',
            $response,
        );
    }

    public function testReorderSucceedsForSameZoneSectionMove(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $section1 = $this->objFromFixture(Section::class, 'section1');
        $section2 = $this->objFromFixture(Section::class, 'section2');

        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $section1->ID,
            'targetParentId' => (int) $page->ID,
            'afterElementID' => $section2->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testResponseMatchesTreeBuilderOutput(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');

        // Build tree directly
        $tree = GridTreeBuilder::create()->buildForPage($page);
        $expected = json_decode(
            json_encode($tree, JSON_THROW_ON_ERROR),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        // Fetch via API
        $response = $this->get($this->apiUrl($page->ID));
        $actual = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expected, $actual);
    }

    // --- apiCreate: zone handling ------------------------------------------------

    public function testCreateSectionUsesExplicitZone(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');

        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'section',
            'parentId' => (int) $page->ID,
            'insertAfterElementID' => null,
            'zone' => 'sidebar',
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // The newest section should have Zone='sidebar'
        $newSection = Section::get()->sort('ID', 'DESC')->first();
        $this->assertSame('sidebar', $newSection->Zone);
    }

    // --- apiReorder: valid operations -------------------------------------------

    public function testReorderSucceedsWithNullAfterElementId(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $section1 = $this->objFromFixture(Section::class, 'section1');

        // Move section to first position (afterElementID=null)
        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $section1->ID,
            'targetParentId' => (int) $page->ID,
            'afterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());
    }

    // --- apiReorder: cross-parent with GridElement target -------------------------

    public function testReorderRowBetweenSectionsSucceeds(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $row1 = $this->objFromFixture(Row::class, 'row1');
        $section2 = $this->objFromFixture(Section::class, 'section2');

        // Move row1 from section1 to section2 (cross-parent, GridElement target)
        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $row1->ID,
            'targetParentId' => (int) $section2->ID,
            'afterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Verify the row is now under section2
        $row1 = Row::get()->byID($row1->ID);
        $this->assertSame((int) $section2->ID, (int) $row1->ParentID);
    }

    // --- Smoke test: parse failure → HTTP 400 wiring -------------------------

    public function testInvalidJsonBodyReturns400(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        // Completely invalid body — all fields wrong types
        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 123,
            'parentId' => 'not-an-int',
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInvalidReorderBodyReturns400(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => 'bad',
            'targetParentId' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- apiCreate: edge cases ------------------------------------------------

    public function testCreateReturns400ForMissingSiteTreeParent(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'section',
            'parentId' => 999999,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateReturns400ForMissingGridElementParent(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'row',
            'parentId' => 999999,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- apiPublish -----------------------------------------------------------

    public function testPublishSucceeds(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section = $this->objFromFixture(Section::class, 'section1');

        $response = $this->patchJson('/admin/grid/api/publish', [
            'id' => $section->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Verify element exists on LIVE stage
        $liveElement = Versioned::withVersionedMode(static function () use ($section): ?GridElement {
            Versioned::set_stage(Versioned::LIVE);

            return GridElement::get()->byID($section->ID);
        });

        $this->assertNotNull($liveElement);
    }

    public function testPublishReturns400ForMissingElement(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->patchJson('/admin/grid/api/publish', [
            'id' => 999999,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPublishReturns400ForInvalidBody(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->patchJson('/admin/grid/api/publish', [
            'id' => 'bad',
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- apiUnpublish ---------------------------------------------------------

    public function testUnpublishSucceeds(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section = $this->objFromFixture(Section::class, 'section1');
        $section->publishRecursive();

        $response = $this->patchJson('/admin/grid/api/unpublish', [
            'id' => $section->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Verify element is gone from LIVE stage
        $liveElement = Versioned::withVersionedMode(static function () use ($section): ?GridElement {
            Versioned::set_stage(Versioned::LIVE);

            return GridElement::get()->byID($section->ID);
        });

        $this->assertNull($liveElement);
    }

    public function testUnpublishReturns400ForMissingElement(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->patchJson('/admin/grid/api/unpublish', [
            'id' => 999999,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUnpublishReturns400ForInvalidBody(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->patchJson('/admin/grid/api/unpublish', [
            'id' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- apiDelete ------------------------------------------------------------

    public function testDeleteSucceeds(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $leaf3 = $this->objFromFixture(GridElement::class, 'leaf3');

        $response = $this->deleteJson('/admin/grid/api/delete', [
            'id' => $leaf3->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // doArchive() removes from both stages
        $archived = GridElement::get()->byID($leaf3->ID);
        $this->assertNull($archived);
    }

    public function testDeleteReturns400ForMissingElement(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->deleteJson('/admin/grid/api/delete', [
            'id' => 999999,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDeleteReturns400ForInvalidBody(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->deleteJson('/admin/grid/api/delete', [
            'id' => -1,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- apiCreateContent -----------------------------------------------------

    public function testCreateContentSucceeds(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $col1->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Verify a ContentElement was created under col1
        $newElement = ContentElement::get()->filter('ParentID', $col1->ID)->sort('ID', 'DESC')->first();
        $this->assertNotNull($newElement);
        $this->assertSame(Column::class, $newElement->ParentClass);
    }

    public function testCreateContentSucceedsWithInsertAfter(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $col1 = $this->objFromFixture(Column::class, 'col1');
        $leaf1 = $this->objFromFixture(GridElement::class, 'leaf1');

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $col1->ID,
            'insertAfterElementID' => $leaf1->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testCreateContentReturns400ForNonColumnParent(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $section1->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateContentReturns400ForMissingParent(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => 999999,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateContentReturns400ForInvalidClassName(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => Section::class,
            'parentId' => (int) $col1->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- apiUpdateGridSettings ------------------------------------------------

    public function testUpdateGridSettingsSucceeds(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        // Use a non-default viewport (lg) so the override is stored in sparse settings
        $response = $this->patchJson('/admin/grid/api/updateGridSettings', [
            'id' => (int) $col1->ID,
            'viewport' => 'lg',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Verify GridSettings JSON was updated — lg differs from md default (12) so it's stored
        /** @var Column $updatedCol */
        $updatedCol = Column::get()->byID($col1->ID);
        $settings = $updatedCol->getGridSettingsData();
        $this->assertArrayHasKey('lg', $settings);
        $this->assertSame(6, $settings['lg']['width']);
    }

    public function testUpdateGridSettingsReturns400ForNonColumn(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        $response = $this->patchJson('/admin/grid/api/updateGridSettings', [
            'id' => (int) $section1->ID,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateGridSettingsReturns400ForInvalidViewport(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        $response = $this->patchJson('/admin/grid/api/updateGridSettings', [
            'id' => (int) $col1->ID,
            'viewport' => 'invalid',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateGridSettingsReturns400ForInvalidBody(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->patchJson('/admin/grid/api/updateGridSettings', [
            'id' => 'bad',
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- getClientConfig ------------------------------------------------------

    public function testGetClientConfigIncludesGridAdapterAndLink(): void
    {
        $this->logInForHttp();

        $controller = GridController::create();
        $config = $controller->getClientConfig();

        $this->assertArrayHasKey('controllerLink', $config);
        $this->assertStringContainsString('grid', $config['controllerLink']);

        $this->assertArrayHasKey('gridAdapter', $config);
        $this->assertArrayHasKey('viewports', $config['gridAdapter']);
        $this->assertArrayHasKey('columnCount', $config['gridAdapter']);
        $this->assertArrayHasKey('defaultViewport', $config['gridAdapter']);
    }

    // --- CSRF enforcement -----------------------------------------------------

    public function testMutatingRequestWithoutCsrfTokenReturns400(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        // Ensure the token is explicitly enabled — the test framework may disable it
        SecurityToken::enable();

        $response = $this->post(
            '/admin/grid/api/create',
            data: null,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['containerType' => 'section', 'parentId' => 1], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Malformed JSON body -------------------------------------------------

    public function testMalformedJsonBodyReturns400(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        SecurityToken::disable();

        try {
            $response = $this->post(
                '/admin/grid/api/create',
                data: null,
                headers: ['Content-Type' => 'application/json'],
                body: 'not-valid-json{',
            );
        } finally {
            SecurityToken::enable();
        }

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Reorder: missing target parent --------------------------------------

    public function testReorderReturns400WhenTargetParentNotFound(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $section1->ID,
            'targetParentId' => 999999,
            'afterElementID' => null,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Permission enforcement (403) ----------------------------------------

    /**
     * Restrict a page so only ADMIN can view or edit it.
     *
     * With CanViewType/CanEditType set to 'OnlyTheseUsers' and no groups
     * assigned, a member with CMS_ACCESS_LeftAndMain (but not ADMIN) will
     * fail all page-delegated permission checks.
     */
    private function restrictPagePermissions(SiteTree $page): void
    {
        $page->CanViewType = 'OnlyTheseUsers';
        $page->CanEditType = 'OnlyTheseUsers';
        $page->write();
    }

    public function testReadTreeReturns403ForRestrictedPage(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $response = $this->get($this->apiUrl($page->ID));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateReturns403WhenParentNotEditable(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'section',
            'parentId' => (int) $page->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateContentReturns403WhenParentNotEditable(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $col1->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPublishReturns403WhenNotPermitted(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $section = $this->objFromFixture(Section::class, 'section1');

        $response = $this->patchJson('/admin/grid/api/publish', [
            'id' => $section->ID,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnpublishReturns403WhenNotPermitted(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $section = $this->objFromFixture(Section::class, 'section1');

        $response = $this->patchJson('/admin/grid/api/unpublish', [
            'id' => $section->ID,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteReturns403WhenNotPermitted(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $leaf3 = $this->objFromFixture(GridElement::class, 'leaf3');

        $response = $this->deleteJson('/admin/grid/api/delete', [
            'id' => $leaf3->ID,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDuplicateReturns403WhenParentNotEditable(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        // canCreate() checks CMS_ACCESS (passes), but Parent()->canEdit()
        // delegates to the restricted page (fails).
        $section = $this->objFromFixture(Section::class, 'section1');

        $response = $this->postJson('/admin/grid/api/duplicate', [
            'id' => $section->ID,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReorderReturns403WhenElementNotEditable(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $section1->ID,
            'targetParentId' => (int) $page->ID,
            'afterElementID' => null,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReorderReturns403WhenTargetParentNotEditable(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        // Target is a different restricted page — element's own canEdit passes
        // (its page is unrestricted) but targetParent->canEdit fails.
        $restrictedPage = TestPage::create();
        $restrictedPage->Title = 'Restricted Target';
        $restrictedPage->CanEditType = 'OnlyTheseUsers';
        $restrictedPage->write();

        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $section1->ID,
            'targetParentId' => (int) $restrictedPage->ID,
            'afterElementID' => null,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Versioned stage: controller must switch to DRAFT internally --------

    public function testCreateContainerFindsParentRegardlessOfAmbientStage(): void
    {
        $this->logInForHttp();

        $pageId = Versioned::withVersionedMode(function (): int {
            Versioned::set_stage(Versioned::DRAFT);

            return (int) $this->objFromFixture(TestPage::class, 'testpage')->ID;
        });

        // Ambient stage is LIVE — the controller must internally switch to DRAFT
        Versioned::set_stage(Versioned::LIVE);

        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'section',
            'parentId' => $pageId,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testCreateContentElementFindsParentRegardlessOfAmbientStage(): void
    {
        $this->logInForHttp();

        $colId = Versioned::withVersionedMode(function (): int {
            Versioned::set_stage(Versioned::DRAFT);

            return (int) $this->objFromFixture(Column::class, 'col1')->ID;
        });

        Versioned::set_stage(Versioned::LIVE);

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => $colId,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testReorderFindsParentRegardlessOfAmbientStage(): void
    {
        $this->logInForHttp();

        [$sectionId, $pageId] = Versioned::withVersionedMode(function (): array {
            Versioned::set_stage(Versioned::DRAFT);

            $section = $this->objFromFixture(Section::class, 'section1');
            $page = $this->objFromFixture(TestPage::class, 'testpage');

            return [(int) $section->ID, (int) $page->ID];
        });

        Versioned::set_stage(Versioned::LIVE);

        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $sectionId,
            'targetParentId' => $pageId,
            'afterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());
    }

    // --- Sort assignment on creation -----------------------------------------

    public function testCreateContainerAssignsSortValue(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');

        $response = $this->postJson('/admin/grid/api/create', [
            'containerType' => 'section',
            'parentId' => (int) $page->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $newSection = Section::get()->sort('ID', 'DESC')->first();
        $this->assertGreaterThan(0, (int) $newSection->Sort);
    }

    public function testCreateContentElementAssignsSortValue(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $col1->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $newElement = ContentElement::get()->filter('ParentID', $col1->ID)->sort('ID', 'DESC')->first();
        $this->assertGreaterThan(0, (int) $newElement->Sort);
    }

    // --- apiReorder: cross-parent source permission --------------------------

    public function testReorderReturns403WhenSourceParentNotEditableOnCrossParentMove(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        // Create a second page (unrestricted) with its own section
        $targetPage = TestPage::create();
        $targetPage->Title = 'Target Page';
        $targetPage->write();

        $targetSection = Section::create();
        $targetSection->Title = 'Target Section';
        $targetSection->ParentID = $targetPage->ID;
        $targetSection->ParentClass = $targetPage::class;
        $targetSection->write();

        // The source page is restricted — source parent canEdit() fails
        $sourcePage = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($sourcePage);

        $row1 = $this->objFromFixture(Row::class, 'row1');

        // Cross-parent move: row1 (under restricted section1) → targetSection (editable)
        $response = $this->patchJson('/admin/grid/api/reorder', [
            'elementID' => $row1->ID,
            'targetParentId' => (int) $targetSection->ID,
            'afterElementID' => null,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- apiDuplicate: sort initialization ------------------------------------

    public function testDuplicateSetsCloneSortToZeroBeforePersistence(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section = $this->objFromFixture(Section::class, 'section1');
        $originalSort = (int) $section->Sort;
        $this->assertGreaterThan(0, $originalSort);

        $response = $this->postJson('/admin/grid/api/duplicate', [
            'id' => $section->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // The clone's Sort should be reassigned by persistDuplicate (placed after original)
        $clone = Section::get()->sort('ID', 'DESC')->first();
        $this->assertGreaterThan($originalSort, (int) $clone->Sort);
    }

    // --- apiCreateContent: ParentClass assignment ----------------------------

    public function testCreateContentSetsParentClassCorrectly(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        $response = $this->postJson('/admin/grid/api/createContent', [
            'className' => ContentElement::class,
            'parentId' => (int) $col1->ID,
            'insertAfterElementID' => null,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $newElement = ContentElement::get()->filter('ParentID', $col1->ID)->sort('ID', 'DESC')->first();
        $this->assertNotNull($newElement);
        $this->assertSame((int) $col1->ID, (int) $newElement->ParentID);
        $this->assertSame(Column::class, $newElement->ParentClass);
    }

    // --- apiDuplicateTo ---

    public function testDuplicateToAppendsSectionAtEndOfTargetZone(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        // Create a target page with an existing section in the 'content' zone
        $targetPage = TestPage::create();
        $targetPage->Title = 'Target Page';
        $targetPage->write();

        $existingSection = Section::create();
        $existingSection->Title = 'Existing';
        $existingSection->ParentID = $targetPage->ID;
        $existingSection->ParentClass = $targetPage::class;
        $existingSection->Zone = 'content';
        $existingSection->Sort = 1;
        $existingSection->write();

        $response = $this->postJson('/admin/grid/api/duplicateTo', [
            'id' => $section1->ID,
            'targetPageId' => (int) $targetPage->ID,
            'targetZone' => 'content',
            'targetParentId' => (int) $targetPage->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Find the clone (newest section)
        $clone = Section::get()->sort('ID', 'DESC')->first();
        $this->assertStringContainsString('copy', $clone->Title);
        $this->assertSame((int) $targetPage->ID, (int) $clone->ParentID);
        $this->assertSame($targetPage::class, $clone->ParentClass);
        $this->assertSame('content', $clone->Zone);
        // Sort should be after the existing section
        $this->assertGreaterThan((int) $existingSection->Sort, (int) $clone->Sort);
    }

    public function testDuplicateToDeepCopiesSectionSubtree(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');
        $originalRowCount = $section1->getChildren()->count();
        $this->assertGreaterThan(0, $originalRowCount);

        $targetPage = TestPage::create();
        $targetPage->Title = 'Deep Copy Target';
        $targetPage->write();

        $response = $this->postJson('/admin/grid/api/duplicateTo', [
            'id' => $section1->ID,
            'targetPageId' => (int) $targetPage->ID,
            'targetZone' => 'main',
            'targetParentId' => (int) $targetPage->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $clone = Section::get()->sort('ID', 'DESC')->first();
        // Deep copy should have duplicated the child rows
        $this->assertSame($originalRowCount, $clone->getChildren()->count());

        // Cloned rows should be different records from the originals
        $originalRowIds = $section1->getChildren()->column('ID');
        $clonedRowIds = $clone->getChildren()->column('ID');
        $this->assertEmpty(array_intersect($originalRowIds, $clonedRowIds));
    }

    public function testDuplicateToChildrenRetainOriginalTitles(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        $targetPage = TestPage::create();
        $targetPage->Title = 'Title Check Target';
        $targetPage->write();

        $response = $this->postJson('/admin/grid/api/duplicateTo', [
            'id' => $section1->ID,
            'targetPageId' => (int) $targetPage->ID,
            'targetZone' => 'main',
            'targetParentId' => (int) $targetPage->ID,
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $clone = Section::get()->sort('ID', 'DESC')->first();
        // Top-level clone gets "copy" in title
        $this->assertStringContainsString('copy', $clone->Title);

        // Child rows should retain their original titles (no "copy" suffix)
        $originalRowTitles = $section1->getChildren()->column('Title');
        $clonedRowTitles = $clone->getChildren()->sort('Sort', 'ASC')->column('Title');
        $this->assertSame($originalRowTitles, $clonedRowTitles);
    }

    public function testDuplicateToReturns400WhenIdMissing(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->postJson('/admin/grid/api/duplicateTo', [
            'targetPageId' => 1,
            'targetZone' => 'main',
            'targetParentId' => 1,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDuplicateToReturns404WhenTargetParentNotFound(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $section1 = $this->objFromFixture(Section::class, 'section1');

        $response = $this->postJson('/admin/grid/api/duplicateTo', [
            'id' => $section1->ID,
            'targetPageId' => 999999,
            'targetZone' => 'main',
            'targetParentId' => 999999,
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateGridSettingsReturns403WhenNotEditable(): void
    {
        $this->logInForHttp();
        Versioned::set_stage(Versioned::DRAFT);

        $page = $this->objFromFixture(TestPage::class, 'testpage');
        $this->restrictPagePermissions($page);

        $col1 = $this->objFromFixture(Column::class, 'col1');

        $response = $this->patchJson('/admin/grid/api/updateGridSettings', [
            'id' => (int) $col1->ID,
            'viewport' => 'lg',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }
}
