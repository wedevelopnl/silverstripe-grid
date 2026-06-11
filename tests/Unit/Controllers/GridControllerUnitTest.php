<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeDevelop\Grid\Controllers\GridController;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\Viewport;

/**
 * Pure-static coverage of {@see GridController::buildAdapterConfig()}.
 *
 * buildAdapterConfig() is a pure transformation over a GridAdapterInterface —
 * it needs neither the SilverStripe Injector nor a database, so these cases run
 * against {@see GridAdapterStub} on a plain PHPUnit TestCase. The Injector-bound
 * getClientConfig() path stays in the integration test
 * ({@see \WeDevelop\Grid\Tests\Integration\Controllers\GridControllerAdapterConfigTest}).
 */
#[CoversClass(GridController::class)]
final class GridControllerUnitTest extends TestCase
{
    public function testBuildAdapterConfigReturnsExpectedStructure(): void
    {
        $config = GridController::buildAdapterConfig(new GridAdapterStub());

        self::assertArrayHasKey('viewports', $config);
        self::assertArrayHasKey('defaultViewport', $config);
        self::assertArrayHasKey('columnCount', $config);
        self::assertArrayHasKey('rowClasses', $config);
        self::assertArrayHasKey('offsetStrategy', $config);
        self::assertArrayHasKey('baseWidthClasses', $config);
        self::assertArrayHasKey('baseOffsetClasses', $config);

        self::assertSame(12, $config['columnCount']);
        self::assertSame('xs', $config['defaultViewport']);
        self::assertSame('row', $config['rowClasses']);
        self::assertSame('margin', $config['offsetStrategy']);
        self::assertCount(3, $config['viewports']);

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

        // First and last viewport values mirror the stub's defaults.
        self::assertSame('xs', $config['viewports'][0]['key']);
        self::assertSame('Extra Small', $config['viewports'][0]['label']);
        self::assertSame(0, $config['viewports'][0]['minWidth']);
        self::assertSame('lg', $config['viewports'][2]['key']);
        self::assertSame(992, $config['viewports'][2]['minWidth']);
    }

    public function testBuildAdapterConfigWidthClassMap(): void
    {
        $config = GridController::buildAdapterConfig(new GridAdapterStub());

        self::assertInstanceOf(stdClass::class, $config['baseWidthClasses']);
        // 12 width classes (1-12)
        $widths = (array) $config['baseWidthClasses'];
        self::assertCount(12, $widths);
        self::assertSame('col-1', $widths[1]);
        self::assertSame('col-12', $widths[12]);
    }

    public function testBuildAdapterConfigOffsetClassMap(): void
    {
        $config = GridController::buildAdapterConfig(new GridAdapterStub());

        self::assertInstanceOf(stdClass::class, $config['baseOffsetClasses']);
        // 12 offset classes (0-11)
        $offsets = (array) $config['baseOffsetClasses'];
        self::assertCount(12, $offsets);
        self::assertSame('offset-0', $offsets[0]);
        self::assertSame('offset-11', $offsets[11]);
    }

    public function testBuildAdapterConfigThrowsWhenAdapterHasNoViewports(): void
    {
        // An adapter that declares no viewports is a misconfiguration: the
        // frontend cannot render a grid without at least one breakpoint.
        // A defaultViewport must be supplied because the stub falls back to
        // viewports[0] otherwise, which is undefined for an empty set.
        $adapter = new GridAdapterStub(
            viewports: [],
            defaultViewport: new Viewport('xs', 'Extra Small', 0),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Adapter must define at least one viewport.');

        GridController::buildAdapterConfig($adapter);
    }
}
