<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\GridAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(GridAdapter::class)]
#[CoversClass(Viewport::class)]
#[CoversClass(OffsetStrategy::class)]
#[CoversClass(AspectRatio::class)]
#[CoversClass(MediaPosition::class)]
#[CoversClass(VerticalAlignment::class)]
#[CoversClass(InvalidGridValueException::class)]
final class GridAdapterTest extends SapphireTest
{
    protected $usesDatabase = false;

    private GridAdapterInterface $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = Injector::inst()->get(GridAdapterInterface::class);
    }

    // -- Grid topology -------------------------------------------------------

    public function testGetViewportsReturnsNonEmptyList(): void
    {
        $viewports = $this->adapter->getViewports();

        self::assertCount(6, $viewports);

        $keys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $viewports,
        );

        self::assertSame(['xs', 'sm', 'md', 'lg', 'xl', 'xxl'], $keys);
    }

    public function testGetViewportsExposeMinWidth(): void
    {
        $viewports = $this->adapter->getViewports();
        $byKey = [];
        foreach ($viewports as $vp) {
            $byKey[$vp->key] = $vp->minWidth;
        }

        self::assertSame(
            ['xs' => 0, 'sm' => 576, 'md' => 768, 'lg' => 992, 'xl' => 1200, 'xxl' => 1400],
            $byKey,
        );
    }

    public function testGetColumnCountReturnsPositiveInt(): void
    {
        self::assertSame(12, $this->adapter->getColumnCount());
    }

    public function testGetDefaultViewportReturnsValidViewport(): void
    {
        $default = $this->adapter->getDefaultViewport();

        self::assertInstanceOf(Viewport::class, $default);
        self::assertSame('md', $default->key);
        self::assertSame('Medium', $default->label);
    }

    public function testGetContainerMaxWidthReturnsPositiveInt(): void
    {
        self::assertSame(1320, $this->adapter->getContainerMaxWidth());
    }

    public function testGetColumnPixelWidth(): void
    {
        self::assertSame(660, $this->adapter->getColumnPixelWidth(6));
        self::assertSame(1320, $this->adapter->getColumnPixelWidth(12));
    }

    public function testGetColumnPixelWidthRoundsCorrectly(): void
    {
        // Use a container width that doesn't divide evenly by 12 to distinguish
        // round() from floor() and ceil().
        Config::modify()->set(BootstrapAdapter::class, 'container_max_width', 1000);
        $adapter = new BootstrapAdapter();

        // 1000 * 5 / 12 = 416.666... → round=417, floor=416 (kills floor mutant)
        self::assertSame(417, $adapter->getColumnPixelWidth(5));

        // 1000 * 1 / 12 = 83.333... → round=83, ceil=84 (kills ceil mutant)
        self::assertSame(83, $adapter->getColumnPixelWidth(1));
    }

    // -- Width classes -------------------------------------------------------

    public function testGetWidthClassForBaseViewport(): void
    {
        $class = $this->adapter->getWidthClass('xs', 6);

        self::assertSame('col-6', $class);
    }

    public function testGetWidthClassForResponsiveViewport(): void
    {
        $class = $this->adapter->getWidthClass('md', 6);

        self::assertSame('col-md-6', $class);
    }

    // -- Offset classes ------------------------------------------------------

    public function testGetOffsetClassForBaseViewport(): void
    {
        $class = $this->adapter->getOffsetClass('xs', 3);

        self::assertSame('offset-3', $class);
    }

    public function testGetOffsetClassForResponsiveViewport(): void
    {
        $class = $this->adapter->getOffsetClass('md', 3);

        self::assertSame('offset-md-3', $class);
    }

    // -- Visibility classes --------------------------------------------------

    public function testGetVisibilityClassesForMiddleViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('md');

        self::assertSame(['d-md-none', 'd-lg-block'], $classes);
    }

    public function testGetVisibilityClassesForLastViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('xxl');

        self::assertSame(['d-xxl-none'], $classes);
    }

    public function testGetVisibilityClassesForBaseViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('xs');

        self::assertSame(['d-none', 'd-sm-block'], $classes);
    }

    public function testGetVisibilityClassesThrowsForInvalidViewport(): void
    {
        $this->expectException(InvalidGridValueException::class);

        $this->adapter->getVisibilityClasses('nonexistent');
    }

    // -- Row, container, title -----------------------------------------------

    public function testGetRowClassesReturnsNonEmptyString(): void
    {
        self::assertSame('row', $this->adapter->getRowClasses());
    }

    public function testGetContainerClassNonFluid(): void
    {
        self::assertSame('container', $this->adapter->getContainerClass(false));
    }

    public function testGetContainerClassFluid(): void
    {
        self::assertSame('container-fluid', $this->adapter->getContainerClass(true));
    }

    public function testGetTitleClassOptionsReturnsNonEmptyArray(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        self::assertNotEmpty($options);
        self::assertIsArray($options);
    }

    // -- Base width/offset classes -------------------------------------------

    public function testGetBaseWidthClass(): void
    {
        self::assertSame('col-6', $this->adapter->getBaseWidthClass(6));
    }

    public function testGetBaseOffsetClass(): void
    {
        self::assertSame('offset-3', $this->adapter->getBaseOffsetClass(3));
    }

    public function testGetBaseOffsetClassAppliesAdjustment(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'offset_adjustment', 1);
        $adapter = new BootstrapAdapter();

        // offset=2, adjustment=1 → 2+1=3, so class should use 3
        // Mutant changes + to -, which would give 2-1=1
        self::assertSame('offset-3', $adapter->getBaseOffsetClass(2));
    }

    // -- Offset strategy -----------------------------------------------------

    public function testGetOffsetStrategyReturnsEnum(): void
    {
        self::assertSame(OffsetStrategy::Margin, $this->adapter->getOffsetStrategy());
    }

    // -- Content layout: aspect ratio ----------------------------------------

    public function testGetAspectRatioClassAutoReturnsNull(): void
    {
        self::assertNull($this->adapter->getAspectRatioClass(AspectRatio::Auto));
    }

    /**
     * @return array<string, array{AspectRatio, string}>
     */
    public static function nonAutoAspectRatioProvider(): array
    {
        return [
            'Square' => [AspectRatio::Square, 'ratio ratio-1x1'],
            'FourByThree' => [AspectRatio::FourByThree, 'ratio ratio-4x3'],
            'SixteenByNine' => [AspectRatio::SixteenByNine, 'ratio ratio-16x9'],
        ];
    }

    #[DataProvider('nonAutoAspectRatioProvider')]
    public function testGetAspectRatioClassNonAutoReturnsString(AspectRatio $ratio, string $expected): void
    {
        self::assertSame($expected, $this->adapter->getAspectRatioClass($ratio));
    }

    public function testGetAspectRatioClassThrowsOnMissingConfig(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'aspect_ratio_classes', []);
        $adapter = new BootstrapAdapter();

        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessage(BootstrapAdapter::class);
        $this->expectExceptionMessage('1x1');

        $adapter->getAspectRatioClass(AspectRatio::Square);
    }

    // -- Content layout: vertical alignment ----------------------------------

    /**
     * @return array<string, array{VerticalAlignment, string}>
     */
    public static function verticalAlignmentProvider(): array
    {
        return [
            'Top' => [VerticalAlignment::Top, 'align-items-start'],
            'Center' => [VerticalAlignment::Center, 'align-items-center'],
            'Bottom' => [VerticalAlignment::Bottom, 'align-items-end'],
        ];
    }

    #[DataProvider('verticalAlignmentProvider')]
    public function testGetVerticalAlignmentClass(VerticalAlignment $alignment, string $expected): void
    {
        self::assertSame($expected, $this->adapter->getVerticalAlignmentClass($alignment));
    }

    // -- Content layout: media/content order ---------------------------------

    /**
     * @return array<string, array{MediaPosition, string, string}>
     */
    public static function mediaPositionProvider(): array
    {
        return [
            'First' => [MediaPosition::First, 'order-1', 'order-2'],
            'Last' => [MediaPosition::Last, 'order-2', 'order-1'],
            'LastOnDesktop' => [MediaPosition::LastOnDesktop, 'order-1 order-md-2', 'order-2 order-md-1'],
        ];
    }

    #[DataProvider('mediaPositionProvider')]
    public function testGetMediaOrderClasses(MediaPosition $position, string $expectedMedia): void
    {
        self::assertSame($expectedMedia, $this->adapter->getMediaOrderClasses($position));
    }

    #[DataProvider('mediaPositionProvider')]
    public function testGetContentOrderClasses(MediaPosition $position, string $_expectedMedia, string $expectedContent): void
    {
        self::assertSame($expectedContent, $this->adapter->getContentOrderClasses($position));
    }

    // -- Content layout: media/content width ---------------------------------

    public function testGetMediaWidthClass(): void
    {
        self::assertSame('col-md-6', $this->adapter->getMediaWidthClass(6));
    }

    public function testGetContentWidthClass(): void
    {
        self::assertSame('col-md-6', $this->adapter->getContentWidthClass(6));
    }

    // -- Content layout: padding ---------------------------------------------

    public function testGetPaddingClass(): void
    {
        self::assertSame('ps-md-3', $this->adapter->getPaddingClass('left', 3));
        self::assertSame('pe-md-3', $this->adapter->getPaddingClass('right', 3));
    }

    // -- Content layout: base column class -----------------------------------

    public function testGetBaseColumnClassReturnsNullForBootstrap(): void
    {
        self::assertNull($this->adapter->getBaseColumnClass());
    }

    // -- Constructor validation ----------------------------------------------

    public function testConstructorThrowsForEmptyViewports(): void
    {
        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessage('The enabled_viewports configuration cannot be an empty array.');

        Config::modify()->set(BootstrapAdapter::class, 'enabled_viewports', []);

        new BootstrapAdapter();
    }

    public function testConstructorThrowsForInvalidDefaultViewport(): void
    {
        $this->expectException(InvalidGridValueException::class);

        Config::modify()->set(BootstrapAdapter::class, 'default_viewport', 'invalid');

        new BootstrapAdapter();
    }

    public function testConstructorThrowsForZeroColumnCount(): void
    {
        $this->expectException(InvalidGridValueException::class);

        Config::modify()->set(BootstrapAdapter::class, 'total_columns', 0);

        new BootstrapAdapter();
    }

    public function testConstructorThrowsForZeroContainerMaxWidth(): void
    {
        $this->expectException(InvalidGridValueException::class);

        Config::modify()->set(BootstrapAdapter::class, 'container_max_width', 0);

        new BootstrapAdapter();
    }

    // -- Malformed viewport_definitions rejection ----------------------------

    public function testMalformedViewportDefinitionsRejectsNonArrayValue(): void
    {
        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessageMatches('/viewport_definitions/i');

        Config::modify()->set(BootstrapAdapter::class, 'viewport_definitions', [
            'md' => 'Medium',   // legacy shape — must be rejected
        ]);

        new BootstrapAdapter();
    }

    public function testMalformedViewportDefinitionsRejectsMissingLabel(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'viewport_definitions', [
            'md' => ['min_width' => 768],
        ]);

        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessageMatches('/label/');

        new BootstrapAdapter();
    }

    public function testMalformedViewportDefinitionsRejectsMissingMinWidth(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'viewport_definitions', [
            'md' => ['label' => 'Medium'],
        ]);

        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessageMatches('/min_width/');

        new BootstrapAdapter();
    }

    public function testMalformedViewportDefinitionsRejectsNegativeMinWidth(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'viewport_definitions', [
            'md' => ['label' => 'Medium', 'min_width' => -1],
        ]);

        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessageMatches('/min_width|negative/');

        new BootstrapAdapter();
    }

    public function testMalformedViewportDefinitionsRejectsNonIntMinWidth(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'viewport_definitions', [
            'md' => ['label' => 'Medium', 'min_width' => '768'],
        ]);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    public function testMalformedViewportDefinitionsRejectsEmptyLabel(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'viewport_definitions', [
            'md' => ['label' => '', 'min_width' => 768],
        ]);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    public function testMalformedViewportDefinitionsRejectsInvalidKeyCharacters(): void
    {
        // Viewport keys flow into `.grid-<key>` CSS class names in the
        // frontend, so keys with whitespace or special characters would
        // produce invalid selectors. The adapter must reject them.
        Config::modify()->set(BootstrapAdapter::class, 'viewport_definitions', [
            'md dirty' => ['label' => 'Medium', 'min_width' => 768],
        ]);

        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessageMatches('/viewport key/i');

        new BootstrapAdapter();
    }

    // -- Enabled viewports filtering -----------------------------------------

    public function testEnabledViewportsFiltersCorrectly(): void
    {
        Config::modify()->set(BootstrapAdapter::class, 'enabled_viewports', ['sm', 'md', 'lg']);
        // Default viewport must be within the enabled set
        Config::modify()->set(BootstrapAdapter::class, 'default_viewport', 'md');

        $filtered = new BootstrapAdapter();

        self::assertCount(3, $filtered->getViewports());

        $keys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $filtered->getViewports(),
        );

        self::assertSame(['sm', 'md', 'lg'], $keys);
    }
}
