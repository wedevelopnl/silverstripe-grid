<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use stdClass;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Controllers\GridController;

#[CoversClass(GridController::class)]
final class GridControllerTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testBuildAdapterConfigReturnsExpectedStructure(): void
    {
        $adapter = Injector::inst()->get(GridAdapterInterface::class);
        $config = GridController::buildAdapterConfig($adapter);

        self::assertArrayHasKey('viewports', $config);
        self::assertArrayHasKey('defaultViewport', $config);
        self::assertArrayHasKey('columnCount', $config);
        self::assertArrayHasKey('rowClasses', $config);
        self::assertArrayHasKey('offsetStrategy', $config);
        self::assertArrayHasKey('baseWidthClasses', $config);
        self::assertArrayHasKey('baseOffsetClasses', $config);

        self::assertSame(12, $config['columnCount']);
        self::assertSame('md', $config['defaultViewport']);
        self::assertSame('row', $config['rowClasses']);
        self::assertSame('margin', $config['offsetStrategy']);
        self::assertCount(6, $config['viewports']);
    }

    public function testBuildAdapterConfigWidthClassMap(): void
    {
        $adapter = Injector::inst()->get(GridAdapterInterface::class);
        $config = GridController::buildAdapterConfig($adapter);

        self::assertInstanceOf(stdClass::class, $config['baseWidthClasses']);
        // 12 width classes (1-12)
        $widths = (array) $config['baseWidthClasses'];
        self::assertCount(12, $widths);
        self::assertSame('col-1', $widths[1]);
        self::assertSame('col-12', $widths[12]);
    }

    public function testBuildAdapterConfigOffsetClassMap(): void
    {
        $adapter = Injector::inst()->get(GridAdapterInterface::class);
        $config = GridController::buildAdapterConfig($adapter);

        self::assertInstanceOf(stdClass::class, $config['baseOffsetClasses']);
        // 12 offset classes (0-11)
        $offsets = (array) $config['baseOffsetClasses'];
        self::assertCount(12, $offsets);
        self::assertSame('offset-0', $offsets[0]);
        self::assertSame('offset-11', $offsets[11]);
    }

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
