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

        self::assertNotEmpty($viewports);

        foreach ($viewports as $viewport) {
            self::assertInstanceOf(Viewport::class, $viewport);
        }
    }

    public function testGetColumnCountReturnsPositiveInt(): void
    {
        self::assertSame(12, $this->adapter->getColumnCount());
    }

    public function testGetDefaultViewportReturnsValidViewport(): void
    {
        $default = $this->adapter->getDefaultViewport();

        self::assertInstanceOf(Viewport::class, $default);

        $keys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $this->adapter->getViewports(),
        );

        self::assertContains($default->key, $keys);
    }

    public function testGetContainerMaxWidthReturnsPositiveInt(): void
    {
        self::assertGreaterThan(0, $this->adapter->getContainerMaxWidth());
    }

    public function testGetColumnPixelWidth(): void
    {
        self::assertGreaterThan(0, $this->adapter->getColumnPixelWidth(6));
        self::assertSame(1320, $this->adapter->getColumnPixelWidth(12));
    }

    // -- Width classes -------------------------------------------------------

    public function testGetWidthClassForBaseViewport(): void
    {
        $class = $this->adapter->getWidthClass('xs', 6);

        self::assertNotEmpty($class);
        self::assertIsString($class);
    }

    public function testGetWidthClassForResponsiveViewport(): void
    {
        $class = $this->adapter->getWidthClass('md', 6);

        self::assertNotEmpty($class);
        self::assertIsString($class);
    }

    // -- Offset classes ------------------------------------------------------

    public function testGetOffsetClassForBaseViewport(): void
    {
        $class = $this->adapter->getOffsetClass('xs', 3);

        self::assertNotEmpty($class);
        self::assertIsString($class);
    }

    public function testGetOffsetClassForResponsiveViewport(): void
    {
        $class = $this->adapter->getOffsetClass('md', 3);

        self::assertNotEmpty($class);
        self::assertIsString($class);
    }

    // -- Visibility classes --------------------------------------------------

    public function testGetVisibilityClassesForMiddleViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('md');

        self::assertCount(2, $classes);

        foreach ($classes as $class) {
            self::assertIsString($class);
            self::assertNotEmpty($class);
        }
    }

    public function testGetVisibilityClassesForLastViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('xxl');

        self::assertCount(1, $classes);
        self::assertNotEmpty($classes[0]);
    }

    public function testGetVisibilityClassesForBaseViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('xs');

        self::assertCount(2, $classes);

        foreach ($classes as $class) {
            self::assertIsString($class);
            self::assertNotEmpty($class);
        }
    }

    public function testGetVisibilityClassesThrowsForInvalidViewport(): void
    {
        $this->expectException(InvalidGridValueException::class);

        $this->adapter->getVisibilityClasses('nonexistent');
    }

    // -- Row, container, title -----------------------------------------------

    public function testGetRowClassesReturnsNonEmptyString(): void
    {
        self::assertNotEmpty($this->adapter->getRowClasses());
    }

    public function testGetContainerClassNonFluid(): void
    {
        self::assertNotEmpty($this->adapter->getContainerClass(false));
    }

    public function testGetContainerClassFluid(): void
    {
        $fluid = $this->adapter->getContainerClass(true);
        $nonFluid = $this->adapter->getContainerClass(false);

        self::assertNotEmpty($fluid);
        self::assertNotSame($fluid, $nonFluid);
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
        self::assertNotEmpty($this->adapter->getBaseWidthClass(6));
    }

    public function testGetBaseOffsetClass(): void
    {
        self::assertNotEmpty($this->adapter->getBaseOffsetClass(3));
    }

    // -- Offset strategy -----------------------------------------------------

    public function testGetOffsetStrategyReturnsEnum(): void
    {
        self::assertInstanceOf(OffsetStrategy::class, $this->adapter->getOffsetStrategy());
    }

    // -- Content layout: aspect ratio ----------------------------------------

    public function testGetAspectRatioClassAutoReturnsNull(): void
    {
        self::assertNull($this->adapter->getAspectRatioClass(AspectRatio::Auto));
    }

    /**
     * @return array<string, array{AspectRatio}>
     */
    public static function nonAutoAspectRatioProvider(): array
    {
        return [
            'Square' => [AspectRatio::Square],
            'FourByThree' => [AspectRatio::FourByThree],
            'SixteenByNine' => [AspectRatio::SixteenByNine],
        ];
    }

    #[DataProvider('nonAutoAspectRatioProvider')]
    public function testGetAspectRatioClassNonAutoReturnsString(AspectRatio $ratio): void
    {
        $class = $this->adapter->getAspectRatioClass($ratio);

        self::assertIsString($class);
        self::assertNotEmpty($class);
    }

    // -- Content layout: vertical alignment ----------------------------------

    /**
     * @return array<string, array{VerticalAlignment}>
     */
    public static function verticalAlignmentProvider(): array
    {
        return [
            'Top' => [VerticalAlignment::Top],
            'Center' => [VerticalAlignment::Center],
            'Bottom' => [VerticalAlignment::Bottom],
        ];
    }

    #[DataProvider('verticalAlignmentProvider')]
    public function testGetVerticalAlignmentClass(VerticalAlignment $alignment): void
    {
        $class = $this->adapter->getVerticalAlignmentClass($alignment);

        self::assertIsString($class);
        self::assertNotEmpty($class);
    }

    // -- Content layout: media/content order ---------------------------------

    /**
     * @return array<string, array{MediaPosition}>
     */
    public static function mediaPositionProvider(): array
    {
        return [
            'First' => [MediaPosition::First],
            'Last' => [MediaPosition::Last],
            'LastOnDesktop' => [MediaPosition::LastOnDesktop],
        ];
    }

    #[DataProvider('mediaPositionProvider')]
    public function testGetMediaOrderClasses(MediaPosition $position): void
    {
        $class = $this->adapter->getMediaOrderClasses($position);

        self::assertIsString($class);
        self::assertNotEmpty($class);
    }

    #[DataProvider('mediaPositionProvider')]
    public function testGetContentOrderClasses(MediaPosition $position): void
    {
        $class = $this->adapter->getContentOrderClasses($position);

        self::assertIsString($class);
        self::assertNotEmpty($class);
    }

    // -- Content layout: media/content width ---------------------------------

    public function testGetMediaWidthClass(): void
    {
        self::assertNotEmpty($this->adapter->getMediaWidthClass(6));
    }

    public function testGetContentWidthClass(): void
    {
        self::assertNotEmpty($this->adapter->getContentWidthClass(6));
    }

    // -- Content layout: padding ---------------------------------------------

    public function testGetPaddingClass(): void
    {
        $left = $this->adapter->getPaddingClass('left', 3);
        $right = $this->adapter->getPaddingClass('right', 3);

        self::assertNotEmpty($left);
        self::assertNotEmpty($right);
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
