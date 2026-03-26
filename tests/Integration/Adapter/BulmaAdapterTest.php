<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\GridAdapter;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

/**
 * Integration tests for BulmaAdapter — viewport definitions, class generation,
 * offset strategy, and visibility with filtered viewport sets.
 */
#[CoversClass(BulmaAdapter::class)]
#[CoversClass(GridAdapter::class)]
final class BulmaAdapterTest extends SapphireTest
{
    protected $usesDatabase = false;

    private BulmaAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new BulmaAdapter();
    }

    public function testImplementsGridAdapterInterface(): void
    {
        $this->assertInstanceOf(GridAdapterInterface::class, $this->adapter);
    }

    public function testImplementsContentLayoutAdapterInterface(): void
    {
        $this->assertInstanceOf(ContentLayoutAdapterInterface::class, $this->adapter);
    }

    public function testGetViewportsReturnsFiveViewports(): void
    {
        $viewports = $this->adapter->getViewports();

        $this->assertCount(5, $viewports);
    }

    public function testGetViewportsReturnsViewportInstances(): void
    {
        $viewports = $this->adapter->getViewports();

        foreach ($viewports as $viewport) {
            $this->assertInstanceOf(Viewport::class, $viewport);
        }
    }

    public function testGetViewportsAreOrderedSmallestToLargest(): void
    {
        $viewports = $this->adapter->getViewports();
        $keys = array_map(
            static fn (Viewport $viewport): string => $viewport->key,
            $viewports,
        );

        $this->assertSame(['mobile', 'tablet', 'desktop', 'widescreen', 'fullhd'], $keys);
    }

    #[DataProvider('viewportDefinitionProvider')]
    public function testGetViewportsHasCorrectDefinitions(
        int $index,
        string $expectedKey,
        string $expectedLabel,
    ): void {
        $viewport = $this->adapter->getViewports()[$index];

        $this->assertSame($expectedKey, $viewport->key);
        $this->assertSame($expectedLabel, $viewport->label);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function viewportDefinitionProvider(): iterable
    {
        yield 'mobile' => [0, 'mobile', 'Mobile'];
        yield 'tablet' => [1, 'tablet', 'Tablet'];
        yield 'desktop' => [2, 'desktop', 'Desktop'];
        yield 'widescreen' => [3, 'widescreen', 'Widescreen'];
        yield 'fullhd' => [4, 'fullhd', 'Full HD'];
    }

    public function testGetColumnCountReturnsTwelve(): void
    {
        $this->assertSame(12, $this->adapter->getColumnCount());
    }

    public function testGetDefaultViewportReturnsDesktop(): void
    {
        $viewport = $this->adapter->getDefaultViewport();

        $this->assertSame('desktop', $viewport->key);
        $this->assertSame('Desktop', $viewport->label);
    }

    #[DataProvider('widthClassProvider')]
    public function testGetWidthClass(string $viewport, int $width, string $expectedClass): void
    {
        $this->assertSame($expectedClass, $this->adapter->getWidthClass($viewport, $width));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function widthClassProvider(): iterable
    {
        // Mobile has no viewport suffix
        yield 'mobile full width' => ['mobile', 12, 'is-12'];
        yield 'mobile half width' => ['mobile', 6, 'is-6'];
        yield 'mobile single column' => ['mobile', 1, 'is-1'];

        // Other viewports include the viewport suffix
        yield 'tablet half width' => ['tablet', 6, 'is-6-tablet'];
        yield 'desktop quarter width' => ['desktop', 3, 'is-3-desktop'];
        yield 'widescreen third width' => ['widescreen', 4, 'is-4-widescreen'];
        yield 'fullhd full width' => ['fullhd', 12, 'is-12-fullhd'];
    }

    #[DataProvider('offsetClassProvider')]
    public function testGetOffsetClass(string $viewport, int $offset, string $expectedClass): void
    {
        $this->assertSame($expectedClass, $this->adapter->getOffsetClass($viewport, $offset));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function offsetClassProvider(): iterable
    {
        // Mobile has no viewport suffix
        yield 'mobile offset 1' => ['mobile', 1, 'is-offset-1'];
        yield 'mobile offset 6' => ['mobile', 6, 'is-offset-6'];

        // Other viewports include the viewport suffix
        yield 'tablet offset 3' => ['tablet', 3, 'is-offset-3-tablet'];
        yield 'desktop offset 4' => ['desktop', 4, 'is-offset-4-desktop'];
        yield 'widescreen offset 2' => ['widescreen', 2, 'is-offset-2-widescreen'];
        yield 'fullhd offset 11' => ['fullhd', 11, 'is-offset-11-fullhd'];
    }

    // ─── getVisibilityClasses (default full set) ─────────────────────

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
        yield 'mobile' => ['mobile', ['is-hidden-mobile', 'is-block-tablet']];
        yield 'tablet' => ['tablet', ['is-hidden-tablet', 'is-block-desktop']];
        yield 'desktop' => ['desktop', ['is-hidden-desktop', 'is-block-widescreen']];
        yield 'widescreen' => ['widescreen', ['is-hidden-widescreen', 'is-block-fullhd']];
        yield 'fullhd' => ['fullhd', ['is-hidden-fullhd']];
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
        yield 'single column' => [1, 'is-1'];
        yield 'half width' => [6, 'is-6'];
        yield 'full width' => [12, 'is-12'];
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
        yield 'offset 1' => [1, 'is-offset-1'];
        yield 'offset 6' => [6, 'is-offset-6'];
        yield 'offset 11' => [11, 'is-offset-11'];
    }

    public function testGetRowClasses(): void
    {
        $this->assertSame('columns is-multiline', $this->adapter->getRowClasses());
    }

    public function testGetContainerClassNonFluid(): void
    {
        $this->assertSame('container', $this->adapter->getContainerClass(false));
    }

    public function testGetContainerClassFluid(): void
    {
        $this->assertSame('container is-fluid', $this->adapter->getContainerClass(true));
    }

    public function testGetTitleClassOptionsReturnsSixOptions(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertCount(6, $options);
    }

    #[DataProvider('titleClassProvider')]
    public function testGetTitleClassOptions(string $cssClass, string $expectedLabel): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertArrayHasKey($cssClass, $options);
        $this->assertSame($expectedLabel, $options[$cssClass]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titleClassProvider(): iterable
    {
        yield 'is-1 — Title 1' => ['is-1', 'Title 1'];
        yield 'is-2 — Title 2' => ['is-2', 'Title 2'];
        yield 'is-3 — Title 3' => ['is-3', 'Title 3'];
        yield 'is-4 — Title 4' => ['is-4', 'Title 4'];
        yield 'is-5 — Title 5' => ['is-5', 'Title 5'];
        yield 'is-6 — Title 6' => ['is-6', 'Title 6'];
    }

    // ─── getContainerMaxWidth ─────────────────────────────────────────

    public function testGetContainerMaxWidthReturns1344(): void
    {
        $this->assertSame(1344, $this->adapter->getContainerMaxWidth());
    }

    // ─── getOffsetStrategy ────────────────────────────────────────────

    public function testGetOffsetStrategyReturnsMargin(): void
    {
        $this->assertSame(OffsetStrategy::Margin, $this->adapter->getOffsetStrategy());
    }

    public function testGetDefaultViewportExistsInViewportList(): void
    {
        $defaultViewport = $this->adapter->getDefaultViewport();
        $viewportKeys = array_map(
            static fn (Viewport $viewport): string => $viewport->key,
            $this->adapter->getViewports(),
        );

        $this->assertContains($defaultViewport->key, $viewportKeys);
    }

    public function testGetViewportsReturnsSameInstancesOnRepeatedCalls(): void
    {
        $first = $this->adapter->getViewports();
        $second = $this->adapter->getViewports();

        $this->assertSame($first, $second);
    }

    // ─── getVisibilityClasses with filtered viewports ────────────────

    /**
     * @param list<string> $enabledViewports
     * @param list<string> $expectedClasses
     */
    #[DataProvider('visibilityClassWithFilteredViewportsProvider')]
    public function testGetVisibilityClassesWithFilteredViewports(array $enabledViewports, string $viewport, array $expectedClasses): void
    {
        BulmaAdapter::config()->set('enabled_viewports', $enabledViewports);
        BulmaAdapter::config()->set('default_viewport', $enabledViewports[0]);

        $adapter = new BulmaAdapter();

        $this->assertSame($expectedClasses, $adapter->getVisibilityClasses($viewport));
    }

    /**
     * @return iterable<string, array{list<string>, string, list<string>}>
     */
    public static function visibilityClassWithFilteredViewportsProvider(): iterable
    {
        // [tablet, desktop, widescreen] — mobile removed
        yield '[tablet,desktop,widescreen] — tablet' => [['tablet', 'desktop', 'widescreen'], 'tablet', ['is-hidden-tablet', 'is-block-desktop']];
        yield '[tablet,desktop,widescreen] — widescreen' => [['tablet', 'desktop', 'widescreen'], 'widescreen', ['is-hidden-widescreen']];

        // [desktop, widescreen, fullhd] — mobile+tablet removed
        yield '[desktop,widescreen,fullhd] — desktop' => [['desktop', 'widescreen', 'fullhd'], 'desktop', ['is-hidden-desktop', 'is-block-widescreen']];

        // [mobile, desktop, fullhd] — gaps
        yield '[mobile,desktop,fullhd] — mobile' => [['mobile', 'desktop', 'fullhd'], 'mobile', ['is-hidden-mobile', 'is-block-desktop']];
        yield '[mobile,desktop,fullhd] — desktop' => [['mobile', 'desktop', 'fullhd'], 'desktop', ['is-hidden-desktop', 'is-block-fullhd']];
        yield '[mobile,desktop,fullhd] — fullhd' => [['mobile', 'desktop', 'fullhd'], 'fullhd', ['is-hidden-fullhd']];

        // [mobile, fullhd] — only 2
        yield '[mobile,fullhd] — mobile' => [['mobile', 'fullhd'], 'mobile', ['is-hidden-mobile', 'is-block-fullhd']];
        yield '[mobile,fullhd] — fullhd' => [['mobile', 'fullhd'], 'fullhd', ['is-hidden-fullhd']];
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
        yield 'square' => [AspectRatio::Square, 'is-1by1'];
        yield '4:3' => [AspectRatio::FourByThree, 'is-4by3'];
        yield '16:9' => [AspectRatio::SixteenByNine, 'is-16by9'];
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
        yield 'top' => [VerticalAlignment::Top, 'is-flex-start'];
        yield 'center' => [VerticalAlignment::Center, 'is-vcentered'];
        yield 'bottom' => [VerticalAlignment::Bottom, 'is-flex-end'];
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
        yield 'first' => [MediaPosition::First, 'has-order-1'];
        yield 'last' => [MediaPosition::Last, 'has-order-2'];
        yield 'last on desktop' => [MediaPosition::LastOnDesktop, 'has-order-1 has-order-2-desktop'];
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
        yield 'first' => [MediaPosition::First, 'has-order-2'];
        yield 'last' => [MediaPosition::Last, 'has-order-1'];
        yield 'last on desktop' => [MediaPosition::LastOnDesktop, 'has-order-2 has-order-1-desktop'];
    }

    // ─── Content layout: width classes ───────────────────────────────

    public function testGetMediaWidthClass(): void
    {
        // Default viewport is desktop, 12 columns, contentColumns=8 → media=4
        $this->assertSame('is-4-desktop', $this->adapter->getMediaWidthClass(8));
    }

    public function testGetContentWidthClass(): void
    {
        $this->assertSame('is-8-desktop', $this->adapter->getContentWidthClass(8));
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
        yield 'left size 3' => ['left', 3, 'pl-3-desktop'];
        yield 'right size 3' => ['right', 3, 'pr-3-desktop'];
        yield 'left size 5' => ['left', 5, 'pl-5-desktop'];
    }

    // ─── Content layout: base column class ───────────────────────────

    public function testGetBaseColumnClassReturnsColumn(): void
    {
        $this->assertSame('column', $this->adapter->getBaseColumnClass());
    }
}
