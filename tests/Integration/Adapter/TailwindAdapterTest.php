<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\GridAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

/**
 * Integration tests for TailwindAdapter — viewport definitions, class generation,
 * offset strategy, and visibility with filtered viewport sets.
 */
#[CoversClass(TailwindAdapter::class)]
#[CoversClass(GridAdapter::class)]
final class TailwindAdapterTest extends SapphireTest
{
    protected $usesDatabase = false;

    private TailwindAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new TailwindAdapter();
    }

    public function testImplementsGridAdapterInterface(): void
    {
        $this->assertInstanceOf(GridAdapterInterface::class, $this->adapter);
    }

    public function testImplementsContentLayoutAdapterInterface(): void
    {
        $this->assertInstanceOf(ContentLayoutAdapterInterface::class, $this->adapter);
    }

    // ── Viewports ──────────────────────────────────────────────

    public function testGetViewportsReturnsFiveViewports(): void
    {
        $viewports = $this->adapter->getViewports();

        $this->assertCount(5, $viewports);
    }

    public function testGetViewportsReturnsViewportInstances(): void
    {
        foreach ($this->adapter->getViewports() as $viewport) {
            $this->assertInstanceOf(Viewport::class, $viewport);
        }
    }

    #[DataProvider('viewportDefinitionProvider')]
    public function testViewportHasExpectedDefinition(
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
        yield 'sm' => [0, 'sm', 'Small'];
        yield 'md' => [1, 'md', 'Medium'];
        yield 'lg' => [2, 'lg', 'Large'];
        yield 'xl' => [3, 'xl', 'Extra Large'];
        yield '2xl' => [4, '2xl', '2X Large'];
    }

    // ── Column count ───────────────────────────────────────────

    public function testGetColumnCountReturnsTwelve(): void
    {
        $this->assertSame(12, $this->adapter->getColumnCount());
    }

    // ── Default viewport ───────────────────────────────────────

    public function testGetDefaultViewportReturnsSm(): void
    {
        $viewport = $this->adapter->getDefaultViewport();

        $this->assertSame('sm', $viewport->key);
        $this->assertSame('Small', $viewport->label);
    }

    public function testGetDefaultViewportIsFirstInViewportList(): void
    {
        $default = $this->adapter->getDefaultViewport();
        $first = $this->adapter->getViewports()[0];

        $this->assertSame($first->key, $default->key);
    }

    // ── Width classes ──────────────────────────────────────────

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
        yield 'sm, 1 column' => ['sm', 1, 'sm:col-span-1'];
        yield 'sm, 6 columns' => ['sm', 6, 'sm:col-span-6'];
        yield 'sm, 12 columns' => ['sm', 12, 'sm:col-span-12'];
        yield 'md, 4 columns' => ['md', 4, 'md:col-span-4'];
        yield 'lg, 8 columns' => ['lg', 8, 'lg:col-span-8'];
        yield 'xl, 3 columns' => ['xl', 3, 'xl:col-span-3'];
        yield '2xl, 12 columns' => ['2xl', 12, '2xl:col-span-12'];
    }

    // ── Offset classes ─────────────────────────────────────────

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
        // col-start is 1-based, so offset N → col-start-(N+1)
        yield 'sm, offset 0' => ['sm', 0, 'sm:col-start-1'];
        yield 'sm, offset 1' => ['sm', 1, 'sm:col-start-2'];
        yield 'sm, offset 3' => ['sm', 3, 'sm:col-start-4'];
        yield 'md, offset 6' => ['md', 6, 'md:col-start-7'];
        yield 'lg, offset 11' => ['lg', 11, 'lg:col-start-12'];
        yield 'xl, offset 0' => ['xl', 0, 'xl:col-start-1'];
        yield '2xl, offset 5' => ['2xl', 5, '2xl:col-start-6'];
    }

    // ── Visibility classes (default full set) ──────────────────

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
        yield 'sm' => ['sm', ['sm:hidden', 'md:block']];
        yield 'md' => ['md', ['md:hidden', 'lg:block']];
        yield 'lg' => ['lg', ['lg:hidden', 'xl:block']];
        yield 'xl' => ['xl', ['xl:hidden', '2xl:block']];
        yield '2xl' => ['2xl', ['2xl:hidden']];
    }

    // ── Base width classes ─────────────────────────────────────

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
        yield 'single column' => [1, 'col-span-1'];
        yield 'half width' => [6, 'col-span-6'];
        yield 'full width' => [12, 'col-span-12'];
    }

    // ── Base offset classes ────────────────────────────────────

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
        yield 'no offset' => [0, 'col-start-1'];
        yield 'offset 3' => [3, 'col-start-4'];
        yield 'offset 11' => [11, 'col-start-12'];
    }

    // ── Row classes ────────────────────────────────────────────

    public function testGetRowClasses(): void
    {
        $this->assertSame('grid grid-cols-12', $this->adapter->getRowClasses());
    }

    // ── Container class ────────────────────────────────────────

    public function testGetContainerClassNonFluid(): void
    {
        $this->assertSame('container mx-auto', $this->adapter->getContainerClass(false));
    }

    public function testGetContainerClassFluid(): void
    {
        $this->assertSame('w-full', $this->adapter->getContainerClass(true));
    }

    // ── Title class options ────────────────────────────────────

    public function testGetTitleClassOptionsReturnsAllSixHeadings(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertCount(6, $options);
    }

    public function testGetTitleClassOptionsKeysAreCssClasses(): void
    {
        $options = $this->adapter->getTitleClassOptions();
        $expectedKeys = ['text-4xl', 'text-3xl', 'text-2xl', 'text-xl', 'text-lg', 'text-base'];

        $this->assertSame($expectedKeys, array_keys($options));
    }

    public function testGetTitleClassOptionsValuesAreHumanReadable(): void
    {
        $options = $this->adapter->getTitleClassOptions();

        foreach ($options as $class => $label) {
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
            $this->assertMatchesRegularExpression('/Heading \d/', $label);
        }
    }

    #[DataProvider('titleClassOptionProvider')]
    public function testTitleClassOption(string $expectedClass, string $expectedLabel): void
    {
        $options = $this->adapter->getTitleClassOptions();

        $this->assertArrayHasKey($expectedClass, $options);
        $this->assertSame($expectedLabel, $options[$expectedClass]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titleClassOptionProvider(): iterable
    {
        yield 'h1 equivalent' => ['text-4xl', 'Heading 1'];
        yield 'h2 equivalent' => ['text-3xl', 'Heading 2'];
        yield 'h3 equivalent' => ['text-2xl', 'Heading 3'];
        yield 'h4 equivalent' => ['text-xl', 'Heading 4'];
        yield 'h5 equivalent' => ['text-lg', 'Heading 5'];
        yield 'h6 equivalent' => ['text-base', 'Heading 6'];
    }

    // ── Container max width ─────────────────────────────────────

    public function testGetContainerMaxWidthReturns1536(): void
    {
        $this->assertSame(1536, $this->adapter->getContainerMaxWidth());
    }

    // ── Offset strategy ─────────────────────────────────────────

    public function testGetOffsetStrategyReturnsGridPlacement(): void
    {
        $this->assertSame(OffsetStrategy::GridPlacement, $this->adapter->getOffsetStrategy());
    }

    // ─── getVisibilityClasses with filtered viewports ────────────────

    /**
     * @param list<string> $enabledViewports
     * @param list<string> $expectedClasses
     */
    #[DataProvider('visibilityClassWithFilteredViewportsProvider')]
    public function testGetVisibilityClassesWithFilteredViewports(array $enabledViewports, string $viewport, array $expectedClasses): void
    {
        TailwindAdapter::config()->set('enabled_viewports', $enabledViewports);
        TailwindAdapter::config()->set('default_viewport', $enabledViewports[0]);

        $adapter = new TailwindAdapter();

        $this->assertSame($expectedClasses, $adapter->getVisibilityClasses($viewport));
    }

    /**
     * @return iterable<string, array{list<string>, string, list<string>}>
     */
    public static function visibilityClassWithFilteredViewportsProvider(): iterable
    {
        // [md, lg, xl] — sm removed
        yield '[md,lg,xl] — md' => [['md', 'lg', 'xl'], 'md', ['md:hidden', 'lg:block']];
        yield '[md,lg,xl] — xl' => [['md', 'lg', 'xl'], 'xl', ['xl:hidden']];

        // [sm, lg, 2xl] — gaps
        yield '[sm,lg,2xl] — sm' => [['sm', 'lg', '2xl'], 'sm', ['sm:hidden', 'lg:block']];
        yield '[sm,lg,2xl] — lg' => [['sm', 'lg', '2xl'], 'lg', ['lg:hidden', '2xl:block']];
        yield '[sm,lg,2xl] — 2xl' => [['sm', 'lg', '2xl'], '2xl', ['2xl:hidden']];
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
        yield 'square' => [AspectRatio::Square, 'aspect-square'];
        yield '4:3' => [AspectRatio::FourByThree, 'aspect-[4/3]'];
        yield '16:9' => [AspectRatio::SixteenByNine, 'aspect-video'];
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
        yield 'top' => [VerticalAlignment::Top, 'items-start'];
        yield 'center' => [VerticalAlignment::Center, 'items-center'];
        yield 'bottom' => [VerticalAlignment::Bottom, 'items-end'];
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
        yield 'last on desktop' => [MediaPosition::LastOnDesktop, 'order-1 sm:order-2'];
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
        yield 'last on desktop' => [MediaPosition::LastOnDesktop, 'order-2 sm:order-1'];
    }

    // ─── Content layout: width classes ───────────────────────────────

    public function testGetMediaWidthClass(): void
    {
        // Default viewport is sm, 12 columns, contentColumns=8 → media=4
        $this->assertSame('sm:col-span-4', $this->adapter->getMediaWidthClass(8));
    }

    public function testGetContentWidthClass(): void
    {
        $this->assertSame('sm:col-span-8', $this->adapter->getContentWidthClass(8));
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
        yield 'left size 3' => ['left', 3, 'sm:pl-3'];
        yield 'right size 3' => ['right', 3, 'sm:pr-3'];
        yield 'left size 5' => ['left', 5, 'sm:pl-5'];
    }

    // ─── Content layout: base column class ───────────────────────────

    public function testGetBaseColumnClassReturnsNull(): void
    {
        $this->assertNull($this->adapter->getBaseColumnClass());
    }
}
