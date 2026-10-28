<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\UserForms\UserFormElement;
use WeDevelop\Grid\UserForms\UserFormElementResolver;

/**
 * The grid-form route serves a shared block's form only where the page shows
 * it: placed in the locale being visited, not in some other one.
 */
#[CoversClass(UserFormElementResolver::class)]
final class FluentUserFormElementResolverTest extends FluentGridTestCase
{
    public function testAFormInABlockThePagePlacesOnlyInAnotherLocaleDoesNotResolve(): void
    {
        $page = $this->createPage('Contact');
        $block = GridTreeFactory::sharedBlock('Contact form');
        GridTreeFactory::reference($page, $block, zone: 'main');

        $resolved = $this->inDutch(function () use ($page, $block): ?UserFormElement {
            $form = $this->formIn($block);

            return $this->resolver()->resolve($page, (int) $form->ID);
        });

        self::assertNull($resolved, 'the Dutch page does not place the block, so it shows no form');
    }

    public function testAFormInABlockThePagePlacesInThisLocaleResolves(): void
    {
        $page = $this->createPage('Contact');
        $block = GridTreeFactory::sharedBlock('Contact form');

        [$form, $resolved] = $this->inDutch(function () use ($page, $block): array {
            $form = $this->formIn($block);
            GridTreeFactory::reference($page, $block, zone: 'main');

            return [$form, $this->resolver()->resolve($page, (int) $form->ID)];
        });

        self::assertSame((int) $form->ID, (int) $resolved?->ID);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function inDutch(callable $callback): mixed
    {
        return FluentState::singleton()->withState(static function (FluentState $state) use ($callback): mixed {
            $state->setLocale('nl_NL');

            return $callback();
        });
    }

    private function formIn(SharedBlock $block): UserFormElement
    {
        $column = GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($block, zone: '')));

        $form = UserFormElement::create();
        $form->ParentID = $column->ID;
        $form->ParentClass = Column::class;
        $form->write();

        return $form;
    }

    private function resolver(): UserFormElementResolver
    {
        return Injector::inst()->get(UserFormElementResolver::class);
    }
}
