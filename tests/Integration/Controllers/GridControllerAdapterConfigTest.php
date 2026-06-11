<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Controllers\GridController;

/**
 * Injector-bound coverage of {@see GridController::getClientConfig()}.
 *
 * The pure-static buildAdapterConfig() transformation is exercised on a plain
 * TestCase against a stub adapter in
 * {@see \WeDevelop\Grid\Tests\Unit\Controllers\GridControllerUnitTest}. This
 * class keeps only the path that genuinely needs the SilverStripe Injector and
 * the AdminController parent bootstrap.
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
        self::assertIsArray($config['gridAdapter']);
        self::assertArrayHasKey('columnCount', $config['gridAdapter']);
    }
}
