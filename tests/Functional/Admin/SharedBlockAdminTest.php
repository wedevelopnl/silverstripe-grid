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
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(SharedBlockAdmin::class)]
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
}
