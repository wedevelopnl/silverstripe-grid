<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\GridAdapter;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

/**
 * Integration tests for BootstrapAdapter — viewport definitions, class generation,
 * offset strategy, and visibility with filtered viewport sets.
 */
#[CoversClass(BootstrapAdapter::class)]
#[CoversClass(GridAdapter::class)]
final class BootstrapAdapterTest extends SapphireTest
{
    protected $usesDatabase = false;

    private BootstrapAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new BootstrapAdapter();
    }

    public function testImplementsGridAdapterInterface(): void
    {
        $this->assertInstanceOf(GridAdapterInterface::class, $this->adapter);
    }

    public function testImplementsContentLayoutAdapterInterface(): void
    {
        $this->assertInstanceOf(ContentLayoutAdapterInterface::class, $this->adapter);
    }

    // ─── getViewports ────────────────────────────────────────────────

    public function testGetViewportsReturnsSixViewports(): void
    {
        $viewports = $this->adapter->getViewports();

        $this->assertCount(6, $viewports);
    }

    public function testGetViewportsReturnsViewportInstances(): void
    {
        $viewports = $this->adapter->getViewports();

        foreach ($viewports as $viewport) {
            $this->assertInstanceOf(Viewport::class, $viewport);
        }
    }

    public function testGetViewportsOrderedSmallestToLargest(): void
    {
        $viewports = $this->adapter->getViewports();
        $keys = array_map(static fn (Viewport $v): string => $v->key, $viewports);

        $this->assertSame(['xs', 'sm', 'md', 'lg', 'xl', 'xxl'], $keys);
    }

    #[DataProvider('viewportDefinitionProvider')]
    public function testViewportDefinition(int $index, string $expectedKey, string $expectedLabel): void
    {
        $viewport = $this->adapter->getViewports()[$index];

        $this->assertSame($expectedKey, $viewport->key);
        $this->assertSame($expectedLabel, $viewport->label);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function viewportDefinitionProvider(): iterable
    {
        yield 'xs — Extra Small' => [0, 'xs', 'Extra Small'];
        yield 'sm — Small' => [1, 'sm', 'Small'];
        yield 'md — Medium' => [2, 'md', 'Medium'];
        yield 'lg — Large' => [3, 'lg', 'Large'];
        yield 'xl — Extra Large' => [4, 'xl', 'Extra Large'];
        yield 'xxl — Extra Extra Large' => [5, 'xxl', 'Extra Extra Large'];
    }

    public function testGetViewportsReturnsSameInstanceOnRepeatedCalls(): void
    {
        $first = $this->adapter->getViewports();
        $second = $this->adapter->getViewports();

        $this->assertSame($first, $second);
    }

    // ─── getColumnCount ──────────────────────────────────────────────

    public function testGetColumnCountReturnsTwelve(): void
    {
        $this->assertSame(12, $this->adapter->getColumnCount());
    }

    // ─── getDefaultViewport ──────────────────────────────────────────

    public function testGetDefaultViewportReturnsMd(): void
    {
        $default = $this->adapter->getDefaultViewport();

        $this->assertSame('md', $default->key);
        $this->assertSame('Medium', $default->label);
    }

    public function testGetDefaultViewportExistsInViewportList(): void
    {
        $default = $this->adapter->getDefaultViewport();
        $viewports = $this->adapter->getViewports();

        $this->assertContains($default, $viewports);
    }

    // ─── getWidthClass ───────────────────────────────────────────────

    #[DataProvider('widthClassProvider')]
    public function testGetWidthClass(string $viewport, int $width, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getWidthClass($viewport, $width));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function widthClassProvider(): iterable
    {
        // xs has no viewport prefix (Bootstrap mobile-first default)
        yield 'xs full width' => ['xs', 12, 'col-12'];
        yield 'xs half width' => ['xs', 6, 'col-6'];
        yield 'xs single column' => ['xs', 1, 'col-1'];

        // All other viewports use the prefix
        yield 'sm 4 columns' => ['sm', 4, 'col-sm-4'];
        yield 'md 6 columns' => ['md', 6, 'col-md-6'];
        yield 'lg 8 columns' => ['lg', 8, 'col-lg-8'];
        yield 'xl 3 columns' => ['xl', 3, 'col-xl-3'];
        yield 'xxl 12 columns' => ['xxl', 12, 'col-xxl-12'];
    }

    // ─── getOffsetClass ──────────────────────────────────────────────

    #[DataProvider('offsetClassProvider')]
    public function testGetOffsetClass(string $viewport, int $offset, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getOffsetClass($viewport, $offset));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function offsetClassProvider(): iterable
    {
        // xs has no viewport prefix
        yield 'xs no offset' => ['xs', 0, 'offset-0'];
        yield 'xs offset 3' => ['xs', 3, 'offset-3'];
        yield 'xs offset 6' => ['xs', 6, 'offset-6'];

        // All other viewports use the prefix
        yield 'sm offset 2' => ['sm', 2, 'offset-sm-2'];
        yield 'md offset 3' => ['md', 3, 'offset-md-3'];
        yield 'lg offset 1' => ['lg', 1, 'offset-lg-1'];
        yield 'xl offset 4' => ['xl', 4, 'offset-xl-4'];
        yield 'xxl offset 0' => ['xxl', 0, 'offset-xxl-0'];
    }

    // ─── getVisibilityClasses (default full set only) ────────────────

    public function testGetVisibilityClassesReturnsTwoClassesForNonLastViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('md');

        $this->assertCount(2, $classes);
    }

    public function testGetVisibilityClassesReturnsOneClassForLastViewport(): void
    {
        $classes = $this->adapter->getVisibilityClasses('xxl');

        $this->assertCount(1, $classes);
    }

    #[DataProvider('defaultVisibilityClassProvider')]
    public function testGetVisibilityClassesDefaultSet(string $viewport, array $expectedClasses): void
    {
        $this->assertSame($expectedClasses, $this->adapter->getVisibilityClasses($viewport));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function defaultVisibilityClassProvider(): iterable
    {
        yield 'xs' => ['xs', ['d-none', 'd-sm-block']];
        yield 'sm' => ['sm', ['d-sm-none', 'd-md-block']];
        yield 'md' => ['md', ['d-md-none', 'd-lg-block']];
        yield 'lg' => ['lg', ['d-lg-none', 'd-xl-block']];
        yield 'xl' => ['xl', ['d-xl-none', 'd-xxl-block']];
        yield 'xxl' => ['xxl', ['d-xxl-none']];
    }

    // ─── getBaseWidthClass ──────────────────────────────────────────

    #[DataProvider('baseWidthClassProvider')]
    public function testGetBaseWidthClass(int $width, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getBaseWidthClass($width));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function baseWidthClassProvider(): iterable
    {
        yield 'single column' => [1, 'col-1'];
        yield 'half width' => [6, 'col-6'];
        yield 'full width' => [12, 'col-12'];
    }

    // ─── getBaseOffsetClass ─────────────────────────────────────────

    #[DataProvider('baseOffsetClassProvider')]
    public function testGetBaseOffsetClass(int $offset, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getBaseOffsetClass($offset));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function baseOffsetClassProvider(): iterable
    {
        yield 'no offset' => [0, 'offset-0'];
        yield 'offset 3' => [3, 'offset-3'];
        yield 'offset 11' => [11, 'offset-11'];
    }

    // ─── getRowClasses ───────────────────────────────────────────────

    public function testGetRowClassesReturnsRow(): void
    {
        $this->assertSame('row', $this->adapter->getRowClasses());
    }

    // ─── getContainerClass ───────────────────────────────────────────

    public function testGetContainerClassNonFluid(): void
    {
        $this->assertSame('container', $this->adapter->getContainerClass(false));
    }

    public function testGetContainerClassFluid(): void
    {
        $this->assertSame('container-fluid', $this->adapter->getContainerClass(true));
    }

    // ─── getTitleClassOptions ────────────────────────────────────────

    public function testGetTitleClassOptionsReturnsTwelveOptions(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertCount(12, $options);
    }

    public function testGetTitleClassOptionsKeysAreCssClasses(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        foreach (array_keys($options) as $cssClass) {
            $this->assertIsString($cssClass);
            $this->assertNotEmpty($cssClass);
        }
    }

    public function testGetTitleClassOptionsValuesAreLabels(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        foreach ($options as $label) {
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function testGetTitleClassOptionsContainsDisplayHeadings(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertArrayHasKey('display-1', $options);
        $this->assertArrayHasKey('display-2', $options);
        $this->assertArrayHasKey('display-3', $options);
        $this->assertArrayHasKey('display-4', $options);
        $this->assertArrayHasKey('display-5', $options);
        $this->assertArrayHasKey('display-6', $options);
    }

    public function testGetTitleClassOptionsContainsStandardHeadings(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertArrayHasKey('h1', $options);
        $this->assertArrayHasKey('h2', $options);
        $this->assertArrayHasKey('h3', $options);
        $this->assertArrayHasKey('h4', $options);
        $this->assertArrayHasKey('h5', $options);
        $this->assertArrayHasKey('h6', $options);
    }

    #[DataProvider('titleClassOptionProvider')]
    public function testGetTitleClassOption(string $cssClass, string $expectedLabel): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertSame($expectedLabel, $options[$cssClass]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titleClassOptionProvider(): iterable
    {
        yield 'display-1' => ['display-1', 'Display 1'];
        yield 'display-2' => ['display-2', 'Display 2'];
        yield 'display-3' => ['display-3', 'Display 3'];
        yield 'display-4' => ['display-4', 'Display 4'];
        yield 'display-5' => ['display-5', 'Display 5'];
        yield 'display-6' => ['display-6', 'Display 6'];
        yield 'h1' => ['h1', 'Heading 1'];
        yield 'h2' => ['h2', 'Heading 2'];
        yield 'h3' => ['h3', 'Heading 3'];
        yield 'h4' => ['h4', 'Heading 4'];
        yield 'h5' => ['h5', 'Heading 5'];
        yield 'h6' => ['h6', 'Heading 6'];
    }

    // ─── getContainerMaxWidth ─────────────────────────────────────────

    public function testGetContainerMaxWidthReturns1320(): void
    {
        $this->assertSame(1320, $this->adapter->getContainerMaxWidth());
    }

    // ─── getOffsetStrategy ────────────────────────────────────────────

    public function testGetOffsetStrategyReturnsMargin(): void
    {
        $this->assertSame(OffsetStrategy::Margin, $this->adapter->getOffsetStrategy());
    }

    // ─── getVisibilityClasses with filtered viewports ────────────────

    /**
     * @param list<string> $enabledViewports
     * @param list<string> $expectedClasses
     */
    #[DataProvider('visibilityClassWithFilteredViewportsProvider')]
    public function testGetVisibilityClassesWithFilteredViewports(array $enabledViewports, string $viewport, array $expectedClasses): void
    {
        BootstrapAdapter::config()->set('enabled_viewports', $enabledViewports);
        BootstrapAdapter::config()->set('default_viewport', $enabledViewports[0]);

        $adapter = new BootstrapAdapter();

        $this->assertSame($expectedClasses, $adapter->getVisibilityClasses($viewport));
    }

    /**
     * @return iterable<string, array{list<string>, string, list<string>}>
     */
    public static function visibilityClassWithFilteredViewportsProvider(): iterable
    {
        // [sm, md, lg] — xs removed; sm uses infix, NOT d-none
        yield '[sm,md,lg] — sm' => [['sm', 'md', 'lg'], 'sm', ['d-sm-none', 'd-md-block']];
        yield '[sm,md,lg] — md' => [['sm', 'md', 'lg'], 'md', ['d-md-none', 'd-lg-block']];
        yield '[sm,md,lg] — lg' => [['sm', 'md', 'lg'], 'lg', ['d-lg-none']];

        // [md, lg, xl, xxl] — xs+sm gone
        yield '[md,lg,xl,xxl] — md' => [['md', 'lg', 'xl', 'xxl'], 'md', ['d-md-none', 'd-lg-block']];

        // [xs, md, xl] — gaps: restore skips to next enabled
        yield '[xs,md,xl] — xs' => [['xs', 'md', 'xl'], 'xs', ['d-none', 'd-md-block']];
        yield '[xs,md,xl] — md' => [['xs', 'md', 'xl'], 'md', ['d-md-none', 'd-xl-block']];
        yield '[xs,md,xl] — xl' => [['xs', 'md', 'xl'], 'xl', ['d-xl-none']];

        // [xs, xxl] — only 2
        yield '[xs,xxl] — xs' => [['xs', 'xxl'], 'xs', ['d-none', 'd-xxl-block']];
        yield '[xs,xxl] — xxl' => [['xs', 'xxl'], 'xxl', ['d-xxl-none']];
    }

    // ─── Content layout: aspect ratio ────────────────────────────────

    #[DataProvider('aspectRatioProvider')]
    public function testGetAspectRatioClass(AspectRatio $ratio, ?string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getAspectRatioClass($ratio));
    }

    /**
     * @return iterable<string, array{AspectRatio, ?string}>
     */
    public static function aspectRatioProvider(): iterable
    {
        yield 'auto' => [AspectRatio::Auto, null];
        yield 'square' => [AspectRatio::Square, 'ratio ratio-1x1'];
        yield '4:3' => [AspectRatio::FourByThree, 'ratio ratio-4x3'];
        yield '16:9' => [AspectRatio::SixteenByNine, 'ratio ratio-16x9'];
    }

    // ─── Content layout: vertical alignment ──────────────────────────

    #[DataProvider('verticalAlignmentProvider')]
    public function testGetVerticalAlignmentClass(VerticalAlignment $alignment, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getVerticalAlignmentClass($alignment));
    }

    /**
     * @return iterable<string, array{VerticalAlignment, string}>
     */
    public static function verticalAlignmentProvider(): iterable
    {
        yield 'top' => [VerticalAlignment::Top, 'align-items-start'];
        yield 'center' => [VerticalAlignment::Center, 'align-items-center'];
        yield 'bottom' => [VerticalAlignment::Bottom, 'align-items-end'];
    }

    // ─── Content layout: media ordering ──────────────────────────────

    #[DataProvider('mediaOrderProvider')]
    public function testGetMediaOrderClasses(MediaPosition $position, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getMediaOrderClasses($position));
    }

    /**
     * @return iterable<string, array{MediaPosition, string}>
     */
    public static function mediaOrderProvider(): iterable
    {
        yield 'first' => [MediaPosition::First, 'order-1'];
        yield 'last' => [MediaPosition::Last, 'order-2'];
        yield 'last on desktop' => [MediaPosition::LastOnDesktop, 'order-1 order-md-2'];
    }

    #[DataProvider('contentOrderProvider')]
    public function testGetContentOrderClasses(MediaPosition $position, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getContentOrderClasses($position));
    }

    /**
     * @return iterable<string, array{MediaPosition, string}>
     */
    public static function contentOrderProvider(): iterable
    {
        yield 'first' => [MediaPosition::First, 'order-2'];
        yield 'last' => [MediaPosition::Last, 'order-1'];
        yield 'last on desktop' => [MediaPosition::LastOnDesktop, 'order-2 order-md-1'];
    }

    // ─── Content layout: width classes ───────────────────────────────

    public function testGetMediaWidthClass(): void
    {
        // Default viewport is md, 12 columns, contentColumns=8 → media=4
        $this->assertSame('col-md-4', $this->adapter->getMediaWidthClass(8));
    }

    public function testGetContentWidthClass(): void
    {
        $this->assertSame('col-md-8', $this->adapter->getContentWidthClass(8));
    }

    // ─── Content layout: padding ─────────────────────────────────────

    #[DataProvider('paddingClassProvider')]
    public function testGetPaddingClass(string $direction, int $size, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getPaddingClass($direction, $size));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function paddingClassProvider(): iterable
    {
        yield 'left size 3' => ['left', 3, 'ps-md-3'];
        yield 'right size 3' => ['right', 3, 'pe-md-3'];
        yield 'left size 5' => ['left', 5, 'ps-md-5'];
    }

    // ─── Content layout: base column class ───────────────────────────

    public function testGetBaseColumnClassReturnsNull(): void
    {
        $this->assertNull($this->adapter->getBaseColumnClass());
    }

    // ─── getColumnPixelWidth ────────────────────────────────────────

    #[DataProvider('columnPixelWidthProvider')]
    public function testGetColumnPixelWidth(int $columnSpan, int $expected): void
    {
        $this->assertSame($expected, $this->adapter->getColumnPixelWidth($columnSpan));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function columnPixelWidthProvider(): iterable
    {
        // Bootstrap: 12 columns, 1320px container
        yield 'full width (12)' => [12, 1320];
        yield 'half width (6)' => [6, 660];
        yield 'third width (4)' => [4, 440];
        yield 'quarter width (3)' => [3, 330];
    }
}
