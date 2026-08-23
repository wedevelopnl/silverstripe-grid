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
        self::assertMatchesRegularExpression(
            '#<th[^>]*col-getRootTypeLabel.*?>Type<#s',
            $body,
            'the header shows the translated label, not the raw method name',
        );
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

    /**
     * Deleting a block reaches every page that places it, and which of the two
     * outcomes applies is the author's call — so the listing, which cannot ask,
     * offers no one-click removal at all. Both stock components have to go:
     * the archive action is what suppresses the stock delete for a versioned
     * model, so removing it alone would leave a permanent delete in its place.
     */
    public function testTheListingOffersNoOneClickRemoval(): void
    {
        $this->populatedBlock('Undeletable from here');

        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        self::assertStringNotContainsString('action--archive', $body);
        self::assertStringNotContainsString('action--delete', $body);
        self::assertStringContainsString(
            'edit-link',
            $body,
            'the row still opens the block, where the two delete actions live',
        );
    }

    /**
     * A GridField, not assembled markup: the row has to arrive through the
     * framework's own column pipeline, which is what escapes the title and
     * would let the list sort and paginate.
     */
    public function testUsedOnTabListsConsumingPagesInAGridField(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block);

        $response = $this->visit($this->editUrl($block));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // Holder ids are form-prefixed, so match the suffix.
        self::assertStringContainsString('_UsedOn', $body);
        self::assertStringContainsString(
            sprintf('<a href="%s">Integration Test Page</a>', $page->getCMSEditLink()),
            $body,
            'each row links into the CMS through the column formatter',
        );
    }

    public function testAnUnusedBlockSaysSoInsteadOfShowingAnEmptyTable(): void
    {
        $block = $this->populatedBlock();

        self::assertStringContainsString(
            'This block is not placed on any page yet.',
            (string) $this->visit($this->editUrl($block))->getBody(),
        );
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

    public function testEditFormOffersTheLibraryDeleteAction(): void
    {
        $block = $this->populatedBlock('Deletable banner');

        $body = (string) $this->visit($this->editUrl($block))->getBody();

        // The outcomes themselves are SharedBlockItemRequestTest's subject;
        // this pins that the admin wires that item request in at all.
        self::assertStringContainsString('name="action_doDeleteSharedBlock"', $body);
    }

    public function testEditFormDropsTheStockArchiveAction(): void
    {
        // The stock button offers one outcome, and the delete reaches every
        // consuming page — which of the two outcomes the author means cannot be
        // inferred, so it is replaced by an action per outcome.
        $block = $this->populatedBlock();

        $body = (string) $this->visit($this->editUrl($block))->getBody();

        self::assertStringNotContainsString('action_doArchive', $body);
    }

    public function testListingOffersTheSplitAddControlWithEveryShapeABlockMayRoot(): void
    {
        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        self::assertStringContainsString('data-shared-block-add', $body);

        // Server-rendered menu items, not a payload for a client-side picker:
        // the element types a leaf-rooted block may take are listed by the same
        // component that validates them.
        self::assertStringContainsString('Add new shared row', $body);
        self::assertStringContainsString('Add new shared column', $body);
        self::assertStringContainsString(
            (string) ContentElement::config()->get('singular_name'),
            $body,
            'the control offers the element types a leaf-rooted block may be seeded with',
        );
    }

    /**
     * One add affordance, not two. The stock button opens an unsaved record —
     * which cannot host the grid editor — so it is removed rather than joined.
     * Nothing replaces its link: every shape is a form action on our own
     * control.
     */
    public function testStockAddButtonIsGoneFromTheListing(): void
    {
        $body = (string) $this->visit('admin/shared-blocks')->getBody();

        self::assertStringNotContainsString('new-link', $body);
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

        $addPosition = strpos($body, 'data-shared-block-add');
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

        self::assertStringNotContainsString('data-shared-block-add', $body);
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
