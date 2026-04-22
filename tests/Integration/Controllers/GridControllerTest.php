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

        // Each viewport entry must have 'key', 'label', and 'minWidth'
        foreach ($config['viewports'] as $viewport) {
            self::assertIsArray($viewport);
            self::assertArrayHasKey('key', $viewport);
            self::assertArrayHasKey('label', $viewport);
            self::assertArrayHasKey('minWidth', $viewport);
            self::assertIsString($viewport['key']);
            self::assertIsString($viewport['label']);
            self::assertIsInt($viewport['minWidth']);
            self::assertGreaterThanOrEqual(0, $viewport['minWidth']);
        }
        // Verify first and last viewport values
        self::assertSame('xs', $config['viewports'][0]['key']);
        self::assertSame('Extra Small', $config['viewports'][0]['label']);
        self::assertSame(0, $config['viewports'][0]['minWidth']);
        self::assertSame('xxl', $config['viewports'][5]['key']);
        self::assertSame(1400, $config['viewports'][5]['minWidth']);
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
