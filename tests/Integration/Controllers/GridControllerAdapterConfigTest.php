<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Controllers\GridController;
use WeDevelop\Grid\Value\AdapterConfig;

/**
 * Injector-bound coverage of {@see GridController::getClientConfig()}.
 *
 * The {@see AdapterConfig} transformation itself is exercised on a plain
 * TestCase against a stub adapter in
 * {@see \WeDevelop\Grid\Tests\Unit\Value\AdapterConfigTest}. This class keeps
 * only the path that genuinely needs the SilverStripe Injector and the
 * AdminController parent bootstrap: that the resolved adapter is wired into the
 * client config.
 */
#[CoversClass(GridController::class)]
final class GridControllerAdapterConfigTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testGetClientConfigIncludesControllerLinkAndAdapter(): void
    {
        /** @var GridController $controller */
        $controller = Injector::inst()->get(GridController::class);
        $config = $controller->getClientConfig();

        self::assertArrayHasKey('controllerLink', $config);
        self::assertArrayHasKey('gridAdapter', $config);
        self::assertNotEmpty($config['controllerLink']);
        self::assertInstanceOf(AdapterConfig::class, $config['gridAdapter']);
        self::assertGreaterThan(0, $config['gridAdapter']->columnCount);
    }

    public function testControllerLinkDropsTheTrailingSlashProjectConfigAdds(): void
    {
        Config::modify()->set(Controller::class, 'add_trailing_slash', true);

        /** @var GridController $controller */
        $controller = Injector::inst()->get(GridController::class);

        self::assertStringEndsWith(
            '/',
            $controller->Link(),
            'precondition: the framework appends a trailing slash under this config',
        );
        self::assertStringEndsWith('/grid', $controller->getClientConfig()['controllerLink']);
    }
}
