<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Forms;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Admin\SharedBlockAdmin;
use WeDevelop\Grid\Forms\SharedBlockItemRequest;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * The library's delete actions, exercised through the form that renders them.
 *
 * These cover what used to be a JSON endpoint plus a React dialog: the choice
 * between the two outcomes is now which button the author submits, and the
 * admin's own confirmation guards the click. Driven over HTTP rather than by
 * poking the handler, so `allowed_actions` and the form dispatch are covered
 * too — that routing is the whole point of moving off the endpoint.
 */
#[CoversClass(SharedBlockItemRequest::class)]
final class SharedBlockItemRequestTest extends FunctionalTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../../Integration/Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        $this->logInAsPageEditor();
    }

    /**
     * Managing a block requires page access and nothing else — the same gate
     * {@see SharedBlock::canDelete()} applies.
     */
    private function logInAsPageEditor(): void
    {
        $memberId = $this->logInWithPermission(['CMS_ACCESS_CMSMain', 'SITETREE_EDIT_ALL']);
        $this->session()->set('loggedInAs', $memberId);
    }

    private function page(): SiteTree
    {
        return $this->objFromFixture(Page::class, 'test_page');
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

    private function itemUrl(SharedBlock $block, string $action): string
    {
        $sanitised = str_replace('\\', '-', SharedBlock::class);

        return "admin/shared-blocks/{$sanitised}/EditForm/field/{$sanitised}/item/{$block->ID}/{$action}";
    }

    private function editForm(SharedBlock $block): string
    {
        return (string) Director::test($this->itemUrl($block, 'edit'), null, $this->session())->getBody();
    }

    private function submit(SharedBlock $block, string $action): HTTPResponse
    {
        $data = [$action => 1];

        if (SecurityToken::is_enabled()) {
            $data['SecurityID'] = SecurityToken::inst()->getValue();
        }

        return Director::test($this->itemUrl($block, 'ItemEditForm'), $data, $this->session(), 'POST');
    }

    /**
     * The whole tag of the named action, attributes in any order. Stock CMS
     * form actions render as `input type=submit` unless they opt into a button
     * tag, and these follow the stock archive in not doing so.
     */
    private function actionTag(string $body, string $action): string
    {
        $pattern = '#<(?:input|button)[^>]*name="' . preg_quote($action, '#') . '"[^>]*>#';
        $matched = preg_match($pattern, $body, $matches);

        self::assertSame(1, $matched, $action . ' is not rendered');

        return $matches[0];
    }

    public function testAnUnplacedBlockOffersASingleDeleteAction(): void
    {
        $body = $this->editForm($this->sectionRootedBlock());

        self::assertStringContainsString('name="action_doDeleteSharedBlock"', $body);
        self::assertStringNotContainsString(
            'name="action_doUnshareSharedBlock"',
            $body,
            'with no placements the two outcomes are identical, so no choice is offered',
        );
        self::assertStringContainsString('value="Delete block"', $body);
    }

    public function testAPlacedBlockOffersBothOutcomesAndNamesItsReach(): void
    {
        $block = $this->sectionRootedBlock();
        GridTreeFactory::reference($this->page(), $block, zone: 'main');
        GridTreeFactory::reference($this->objFromFixture(Page::class, 'test_page_2'), $block, zone: 'main');

        $body = $this->editForm($block);

        self::assertStringContainsString('value="Delete and remove from 2 pages"', $body);
        self::assertStringContainsString('value="Delete and keep a copy on each page"', $body);
    }

    public function testASinglePlacementIsNamedInTheSingular(): void
    {
        $block = $this->sectionRootedBlock();
        GridTreeFactory::reference($this->page(), $block, zone: 'main');

        self::assertStringContainsString('value="Delete and remove from 1 page"', $this->editForm($block));
    }

    /**
     * The admin binds its confirmation to `.action--delete` inside the edit
     * form's toolbar. Without the class the click is unguarded, so this is the
     * whole of the safety net rather than a styling detail.
     */
    public function testEveryDeleteActionCarriesTheAdminsConfirmationClass(): void
    {
        $block = $this->sectionRootedBlock();
        GridTreeFactory::reference($this->page(), $block, zone: 'main');

        $body = $this->editForm($block);

        foreach (['action_doDeleteSharedBlock', 'action_doUnshareSharedBlock'] as $action) {
            self::assertStringContainsString(
                'action--delete',
                $this->actionTag($body, $action),
                $action . ' must be confirmed before it fires',
            );
        }
    }

    /**
     * Blocks are created seeded, root and all. The stock `item/new` route would
     * produce one with no root at all, so it is closed rather than left as an
     * undiscoverable second way in.
     */
    public function testTheUnsavedBlockFormIsRefused(): void
    {
        $sanitised = str_replace('\\', '-', SharedBlock::class);
        $url = "admin/shared-blocks/{$sanitised}/EditForm/field/{$sanitised}/item/new";

        $response = Director::test($url, null, $this->session());

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    public function testRemoveModeArchivesTheBlockAndItsPlacements(): void
    {
        $block = $this->sectionRootedBlock();
        $reference = GridTreeFactory::reference($this->page(), $block, zone: 'main');

        $response = $this->submit($block, 'action_doDeleteSharedBlock');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('admin/shared-blocks', (string) $response->getHeader('Location'));
        self::assertNull(SharedBlock::get()->byID($block->ID));
        self::assertNull(SharedBlockReference::get()->byID($reference->ID));
    }

    public function testUnshareModeLeavesAnIndependentCopyOnThePage(): void
    {
        $block = $this->sectionRootedBlock();
        GridTreeFactory::reference($this->page(), $block, zone: 'main');

        $this->submit($block, 'action_doUnshareSharedBlock');

        self::assertNull(SharedBlock::get()->byID($block->ID));
        self::assertCount(0, SharedBlockReference::get());
        self::assertCount(
            1,
            ContentElement::get()->filter(['Title' => 'Shared leaf']),
            'the page keeps the content as its own copy',
        );
    }

    public function testDeletingRecordsANewDraftVersionOnEveryConsumingPage(): void
    {
        $block = $this->sectionRootedBlock();
        $page = $this->page();
        GridTreeFactory::reference($page, $block, zone: 'main');

        $before = (int) $page->Version;

        $this->submit($block, 'action_doDeleteSharedBlock');

        $reloaded = SiteTree::get()->byID($page->ID);

        self::assertNotNull($reloaded);
        self::assertGreaterThan(
            $before,
            (int) $reloaded->Version,
            'the page renders something else now, so its draft has to say so',
        );
    }

    public function testUnsharingABlockWithNothingToCopyIsRefused(): void
    {
        $block = GridTreeFactory::sharedBlock('Empty block');
        $reference = GridTreeFactory::reference($this->page(), $block, zone: 'main');

        $this->submit($block, 'action_doUnshareSharedBlock');

        self::assertNotNull(SharedBlock::get()->byID($block->ID), 'the block survives');
        self::assertNotNull(SharedBlockReference::get()->byID($reference->ID));
    }

    public function testAMemberWithoutPageAccessCannotDeleteThroughTheForm(): void
    {
        $block = $this->sectionRootedBlock();

        // A CMS grant that is not page access: the library is gated on the
        // literal CMS_ACCESS_CMSMain, so this reaches neither screen nor action.
        $memberId = $this->logInWithPermission('CMS_ACCESS_' . SharedBlockAdmin::class);
        $this->session()->set('loggedInAs', $memberId);

        $this->submit($block, 'action_doDeleteSharedBlock');

        self::assertNotNull(SharedBlock::get()->byID($block->ID), 'the block survives');
    }
}
