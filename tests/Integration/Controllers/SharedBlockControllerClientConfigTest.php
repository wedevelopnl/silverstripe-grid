<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Admin\AdminController;
use SilverStripe\Admin\CMSMenu;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Controllers\GridApiController;
use WeDevelop\Grid\Controllers\GridController;
use WeDevelop\Grid\Controllers\SharedBlockController;

/**
 * The block library is a second AdminController, so the CMS publishes it as its
 * own client-config section. The frontend resolves the two base URLs by FQCN and
 * must not be able to conflate them.
 */
#[CoversClass(SharedBlockController::class)]
#[CoversClass(GridApiController::class)]
final class SharedBlockControllerClientConfigTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testItPublishesItsOwnControllerLinkDistinctFromTheGridOne(): void
    {
        /** @var SharedBlockController $blockController */
        $blockController = Injector::inst()->get(SharedBlockController::class);
        /** @var GridController $gridController */
        $gridController = Injector::inst()->get(GridController::class);

        $blockLink = $blockController->getClientConfig()['controllerLink'];

        self::assertStringEndsWith('/grid-shared-blocks', $blockLink);
        self::assertNotSame(
            $gridController->getClientConfig()['controllerLink'],
            $blockLink,
            'the client picks a base URL per controller; identical links would route block calls to the grid',
        );
    }

    public function testItCarriesNoAdapterConfig(): void
    {
        /** @var SharedBlockController $controller */
        $controller = Injector::inst()->get(SharedBlockController::class);

        // The adapter is published once, on the grid section, and read from
        // there by getAdapterConfig(). A second copy would be a second source of
        // truth for the viewport/column vocabulary.
        self::assertArrayNotHasKey('gridAdapter', $controller->getClientConfig());
    }

    public function testTheCmsEnumeratesBothControllersAndNotTheAbstractBase(): void
    {
        // This is the list LeftAndMain::getCombinedClientConfig() instantiates to
        // build `sections`, and that AdminRootController turns into routes. Both
        // concrete controllers must be in it — config.ts looks each up by exact
        // FQCN. The abstract base must NOT be: it is only excluded because
        // CMSMenu drops non-instantiable classes, and were that to change the CMS
        // would fatal on every admin page load trying to construct it.
        $classes = CMSMenu::get_cms_classes(AdminController::class, true, CMSMenu::URL_PRIORITY);

        self::assertContains(GridController::class, $classes);
        self::assertContains(SharedBlockController::class, $classes);
        self::assertNotContains(GridApiController::class, $classes);
    }
}
