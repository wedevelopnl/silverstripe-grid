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
