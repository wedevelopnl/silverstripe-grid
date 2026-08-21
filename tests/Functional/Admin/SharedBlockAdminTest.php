<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Admin;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Admin\SharedBlockAdmin;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Forms\GridFieldAddSharedBlockButton;
use WeDevelop\Grid\Tests\Integration\Support\DenyBlockCreateExtension;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(SharedBlockAdmin::class)]
#[CoversClass(GridFieldAddSharedBlockButton::class)]
final class SharedBlockAdminTest extends FunctionalTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../../Integration/Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        $memberId = $this->logInWithPermission('ADMIN');
        $this->session()->set('loggedInAs', $memberId);
    }

    private function visit(string $url): HTTPResponse
    {
        return Director::test($url, null, $this->session());
    }

    private function editUrl(SharedBlock $block): string
    {
        $sanitised = str_replace('\\', '-', SharedBlock::class);

        return "admin/shared-blocks/{$sanitised}/EditForm/field/{$sanitised}/item/{$block->ID}/edit";
    }

    private function populatedBlock(string $title = 'Shared banner'): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock($title);
        GridTreeFactory::section($block, zone: '', title: 'Shared section');

        return $block;
    }

    public function testAdminListsBlocks(): void
    {
        $this->populatedBlock('Listed banner');

        $response = $this->visit('admin/shared-blocks');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Listed banner', (string) $response->getBody());
    }

    public function testListingNamesEachBlocksRootType(): void
    {
        // Which shape a block roots decides where it can be placed, so the
        // library has to say it without opening the block.
        $this->populatedBlock('Typed banner');

        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        self::assertStringContainsString('<td class="col-Title">Typed banner', $body);
        self::assertStringContainsString(
            '<td class="col-getRootTypeLabel">Section</td>',
            $body,
            'a section-rooted block is listed as a Section',
        );
    }

    public function testBlockEditFormEmbedsTheGridEditorRootedAtTheBlock(): void
    {
        $block = $this->populatedBlock();

        $sanitised = str_replace('\\', '-', SharedBlock::class);
        $response = $this->visit(
            "admin/shared-blocks/{$sanitised}/EditForm/field/{$sanitised}/item/{$block->ID}/edit",
        );

        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('data-react-mount="grid-editor"', $body);
        self::assertStringContainsString('grid-root-type', $body);
        self::assertStringContainsString('sharedBlock', $body);
    }

    public function testBlockEditLinkResolvesToItsOwnForm(): void
    {
        // The link a placement's view/edit actions follow. It must reach the
        // BLOCK's form, not the form of whichever element roots it.
        $block = $this->populatedBlock('Linked banner');

        $link = $block->getCMSEditLink();
        self::assertNotNull($link);
        self::assertStringEndsWith($this->editUrl($block), $link);

        $response = $this->visit($link);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'the generated edit link must resolve; fix the URL builder, not this test',
        );
        self::assertStringContainsString('Linked banner', (string) $response->getBody());
    }

    public function testBlockEditLinkIsNullBeforeTheBlockIsSaved(): void
    {
        self::assertNull(SharedBlock::create()->getCMSEditLink());
    }

    public function testSharedElementEditLinkResolves(): void
    {
        // Pins the nested ModelAdmin URL shape built by
        // GridElement::buildSharedBlockEditLink() against the running CMS.
        $block = $this->populatedBlock();
        $section = $block->getRootElement();
        self::assertNotNull($section);

        $link = $section->getCMSEditLink();
        self::assertNotNull($link);

        $response = $this->visit($link);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'the generated edit link must resolve; fix the URL builder, not this test',
        );
        self::assertStringContainsString('Shared section', (string) $response->getBody());
    }

    public function testUsedOnTabListsConsumingPages(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block);

        $sanitised = str_replace('\\', '-', SharedBlock::class);
        $response = $this->visit(
            "admin/shared-blocks/{$sanitised}/EditForm/field/{$sanitised}/item/{$block->ID}/edit",
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Integration Test Page', (string) $response->getBody());
    }

    public function testPageElementEditLinkIsUnchanged(): void
    {
        // Regression: the page path must stay byte-identical.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');

        self::assertStringContainsString(
            "/field/GridEditor/item/{$section->ID}/edit",
            (string) $section->getCMSEditLink(),
        );
    }

    public function testEditFormOffersTheLibraryDeleteTrigger(): void
    {
        $block = $this->populatedBlock('Deletable banner');

        $body = (string) $this->visit($this->editUrl($block))->getBody();

        self::assertStringContainsString(
            sprintf('data-grid-shared-block-delete="%d"', (int) $block->ID),
            $body,
        );
        self::assertStringContainsString('data-grid-shared-block-title="Deletable banner"', $body);
        // Absolute on purpose: the front end assigns it to location from a URL
        // several segments deep, where a relative link resolves against the
        // edit form's own path.
        self::assertMatchesRegularExpression(
            '#data-grid-shared-block-return="https?://[^"]*/admin/shared-blocks[^"]*"#',
            $body,
            'the trigger must carry an absolute link back to the library',
        );
    }

    public function testEditFormDropsTheStockArchiveAction(): void
    {
        // The stock button archives on a generic "are you sure?", which cannot
        // convey that the delete reaches every consuming page — nor ask which
        // of the two outcomes the author means.
        $block = $this->populatedBlock();

        $body = (string) $this->visit($this->editUrl($block))->getBody();

        self::assertStringNotContainsString('action_doArchive', $body);
    }

    public function testListingOffersTheSplitAddControlCarryingTheLeafTypes(): void
    {
        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        self::assertStringContainsString('data-grid-add-shared-block', $body);

        // Decoded, not merely searched for: the payload is base64 precisely
        // because the template layer mangles the backslashes in raw JSON class
        // names, and a substring check would not have caught that.
        self::assertArrayHasKey(
            ContentElement::class,
            $this->leafTypesFrom($body),
            'the control carries the element types a leaf-rooted block may be seeded with',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function leafTypesFrom(string $body): array
    {
        self::assertSame(
            1,
            preg_match('/data-grid-leaf-types="([^"]*)"/', $body, $matches),
            'the add control must carry a leaf-type payload',
        );

        $decoded = json_decode(
            (string) base64_decode(html_entity_decode($matches[1]), true),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * One add affordance, not two. The stock button opens an unsaved record —
     * which cannot host the grid editor — so it is removed rather than joined,
     * and the only `new-link` left in the listing is our own fallback.
     */
    public function testStockAddButtonIsGoneFromTheListing(): void
    {
        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        self::assertSame(1, substr_count($body, 'new-link'));
    }

    /**
     * The primary action leads the toolbar, as it does in every stock
     * ModelAdmin. Fragments concatenate in component order, so simply appending
     * the replacement lands it last in the row — behind Export, Print and
     * Import — which is why it is inserted at the stock button's position
     * before that button is removed.
     */
    public function testAddControlLeadsTheListingToolbar(): void
    {
        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        $addPosition = strpos($body, 'data-grid-add-shared-block');
        $exportPosition = strpos($body, 'action_export');

        self::assertIsInt($addPosition);
        self::assertIsInt($exportPosition);
        self::assertLessThan(
            $exportPosition,
            $addPosition,
            'the add control must render ahead of the export button, which is the first of the stock toolbar buttons',
        );
    }

    public function testNoAddControlForAMemberWhoMayNotCreateBlocks(): void
    {
        SharedBlock::add_extension(DenyBlockCreateExtension::class);

        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        self::assertStringNotContainsString('data-grid-add-shared-block', $body);
        self::assertStringNotContainsString('new-link', $body);
    }

    public function testStockArchiveActionSurvivesForOtherRecordTypes(): void
    {
        // The extension is registered on the shared item request base class, so
        // it sees every GridField detail form in the CMS. A page's own elements
        // must keep their normal actions.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');

        $body = (string) $this->visit((string) $section->getCMSEditLink())->getBody();

        self::assertStringNotContainsString('data-grid-shared-block-delete', $body);
        // The half that actually guards the regression: moving the two
        // removeByName() calls above the `!$record instanceof SharedBlock`
        // guard would strip archive from every GridField detail form in the
        // CMS, which asserting only on the shared-block trigger cannot see.
        self::assertStringContainsString(
            'action_doArchive',
            $body,
            'a non-block record must keep its stock archive action',
        );
    }
}
