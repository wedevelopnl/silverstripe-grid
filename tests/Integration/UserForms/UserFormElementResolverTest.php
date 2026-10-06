<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\UserForms;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\UserForms\UserFormElement;
use WeDevelop\Grid\UserForms\UserFormElementResolver;

#[CoversClass(UserFormElementResolver::class)]
final class UserFormElementResolverTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testAFormOnThePageResolves(): void
    {
        $page = $this->page('test_page');
        $form = $this->formIn($this->columnIn($page));

        self::assertSame((int) $form->ID, (int) $this->resolver()->resolve($page, (int) $form->ID)?->ID);
    }

    public function testAFormInASharedBlockPlacedOnThePageResolves(): void
    {
        $page = $this->page('test_page');
        [$block, $form] = $this->sharedForm();
        GridTreeFactory::reference($page, $block, sort: 1);

        self::assertSame((int) $form->ID, (int) $this->resolver()->resolve($page, (int) $form->ID)?->ID);
    }

    public function testAFormOnAnotherPageDoesNotResolve(): void
    {
        $form = $this->formIn($this->columnIn($this->page('test_page_2')));

        self::assertNull($this->resolver()->resolve($this->page('test_page'), (int) $form->ID));
    }

    public function testAFormInABlockThePageDoesNotPlaceDoesNotResolve(): void
    {
        [$block, $form] = $this->sharedForm();
        GridTreeFactory::reference($this->page('test_page_2'), $block, sort: 1);

        self::assertNull($this->resolver()->resolve($this->page('test_page'), (int) $form->ID));
    }

    public function testAnElementThatIsNotAFormDoesNotResolve(): void
    {
        $page = $this->page('test_page');
        $content = GridTreeFactory::contentElement($this->columnIn($page));

        self::assertNull($this->resolver()->resolve($page, (int) $content->ID));
    }

    public function testAnUnknownIdDoesNotResolve(): void
    {
        self::assertNull($this->resolver()->resolve($this->page('test_page'), 999999));
    }

    public function testAPlacementThatOnlyExistsOnDraftDoesNotResolveOnLive(): void
    {
        $page = $this->page('test_page');
        [$block, $form] = $this->sharedForm();
        $block->publishRecursive();
        $page->publishRecursive();
        GridTreeFactory::reference($page, $block, sort: 1);

        Versioned::set_stage(Versioned::LIVE);

        self::assertNull($this->resolver()->resolve($page, (int) $form->ID));
    }

    private function resolver(): UserFormElementResolver
    {
        return Injector::inst()->get(UserFormElementResolver::class);
    }

    private function page(string $id): Page
    {
        return $this->objFromFixture(Page::class, $id);
    }

    private function columnIn(Page|SharedBlock $parent): Column
    {
        return GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($parent)));
    }

    /** @return array{SharedBlock, UserFormElement} */
    private function sharedForm(): array
    {
        $block = GridTreeFactory::sharedBlock('Contact');

        return [$block, $this->formIn($this->columnIn($block))];
    }

    private function formIn(Column $column): UserFormElement
    {
        $form = UserFormElement::create();
        $form->ParentID = $column->ID;
        $form->ParentClass = Column::class;
        $form->write();

        return $form;
    }
}
