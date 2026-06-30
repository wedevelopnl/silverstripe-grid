<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\GridAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

/**
 * Behaviour of the config-driven {@see GridAdapter} base class, asserted against
 * every shipped preset.
 *
 * These methods emit framework-specific CSS classes and topology, so each output
 * is parametrised over all three presets (Bootstrap, Tailwind, Bulma) with the
 * expected value per preset. A new preset added to this matrix is verified end to
 * end; a base-class change that breaks one framework's output fails loudly here.
 *
 * Providers yield the adapter class-string (not an instance): PHPUnit evaluates
 * data providers before the SilverStripe config manifest is booted, and the
 * adapter constructor reads config, so each test instantiates inside its body.
 *
 * The no-infix base-viewport branch (the TRUE arm of `$viewport === base_viewport_key`)
 * only applies to presets that declare a base viewport — Bootstrap (`xs`) and Bulma
 * (`mobile`); Tailwind has none, so it is excluded from those providers and only ever
 * exercises the responsive arm. Generic base-class validation (malformed config,
 * pixel rounding) is not framework-specific output, so it is asserted once against
 * the default preset (Tailwind) rather than redundantly across all three.
 */
#[CoversClass(GridAdapter::class)]
#[CoversClass(BootstrapAdapter::class)]
#[CoversClass(TailwindAdapter::class)]
#[CoversClass(BulmaAdapter::class)]
#[CoversClass(Viewport::class)]
#[CoversClass(OffsetStrategy::class)]
#[CoversClass(AspectRatio::class)]
#[CoversClass(MediaPosition::class)]
#[CoversClass(VerticalAlignment::class)]
#[CoversClass(InvalidGridValueException::class)]
final class GridAdapterTest extends SapphireTest
{
    protected $usesDatabase = false;

    // -- Grid topology -------------------------------------------------------

    /**
     * @return iterable<string, array{class-string<GridAdapter>, list<string>}>
     */
    public static function viewportKeysProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, ['xs', 'sm', 'md', 'lg', 'xl', 'xxl']];
        yield 'tailwind' => [TailwindAdapter::class, ['sm', 'md', 'lg', 'xl', '2xl']];
        yield 'bulma' => [BulmaAdapter::class, ['mobile', 'tablet', 'desktop', 'widescreen', 'fullhd']];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     * @param list<string> $expectedKeys
     */
    #[DataProvider('viewportKeysProvider')]
    public function testGetViewportsReturnsKeysInBreakpointOrder(string $adapterClass, array $expectedKeys): void
    {
        $keys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            (new $adapterClass())->getViewports(),
        );

        self::assertSame($expectedKeys, $keys);
    }

    /**
     * @return iterable<string, array{class-string<GridAdapter>, array<string, int>}>
     */
    public static function viewportMinWidthProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, ['xs' => 0, 'sm' => 576, 'md' => 768, 'lg' => 992, 'xl' => 1200, 'xxl' => 1400]];
        yield 'tailwind' => [TailwindAdapter::class, ['sm' => 640, 'md' => 768, 'lg' => 1024, 'xl' => 1280, '2xl' => 1536]];
        yield 'bulma' => [BulmaAdapter::class, ['mobile' => 0, 'tablet' => 769, 'desktop' => 1024, 'widescreen' => 1216, 'fullhd' => 1408]];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     * @param array<string, int> $expected
     */
    #[DataProvider('viewportMinWidthProvider')]
    public function testGetViewportsExposeMinWidth(string $adapterClass, array $expected): void
    {
        $byKey = [];
        foreach ((new $adapterClass())->getViewports() as $vp) {
            $byKey[$vp->key] = $vp->minWidth;
        }

        self::assertSame($expected, $byKey);
    }

    /**
     * @return iterable<string, array{class-string<GridAdapter>}>
     */
    public static function allAdaptersProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class];
        yield 'tailwind' => [TailwindAdapter::class];
        yield 'bulma' => [BulmaAdapter::class];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('allAdaptersProvider')]
    public function testGetColumnCountReturnsTwelve(string $adapterClass): void
    {
        self::assertSame(12, (new $adapterClass())->getColumnCount());
    }

    /**
     * @return iterable<string, array{class-string<GridAdapter>, string, string}>
     */
    public static function defaultViewportProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'md', 'Medium'];
        yield 'tailwind' => [TailwindAdapter::class, 'sm', 'Small'];
        yield 'bulma' => [BulmaAdapter::class, 'desktop', 'Desktop'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('defaultViewportProvider')]
    public function testGetDefaultViewport(string $adapterClass, string $expectedKey, string $expectedLabel): void
    {
        $default = (new $adapterClass())->getDefaultViewport();

        self::assertSame($expectedKey, $default->key);
        self::assertSame($expectedLabel, $default->label);
    }

    /**
     * @return iterable<string, array{class-string<GridAdapter>, int}>
     */
    public static function containerMaxWidthProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 1320];
        yield 'tailwind' => [TailwindAdapter::class, 1536];
        yield 'bulma' => [BulmaAdapter::class, 1344];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('containerMaxWidthProvider')]
    public function testGetContainerMaxWidth(string $adapterClass, int $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getContainerMaxWidth());
    }

    /**
     * Pixel width for half (6/12) and full (12/12) of the container.
     *
     * @return iterable<string, array{class-string<GridAdapter>, int, int}>
     */
    public static function columnPixelWidthProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 660, 1320];
        yield 'tailwind' => [TailwindAdapter::class, 768, 1536];
        yield 'bulma' => [BulmaAdapter::class, 672, 1344];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('columnPixelWidthProvider')]
    public function testGetColumnPixelWidth(string $adapterClass, int $expectedHalf, int $expectedFull): void
    {
        $adapter = new $adapterClass();

        self::assertSame($expectedHalf, $adapter->getColumnPixelWidth(6));
        self::assertSame($expectedFull, $adapter->getColumnPixelWidth(12));
    }

    public function testGetColumnPixelWidthRoundsCorrectly(): void
    {
        // Generic rounding logic (not framework-specific output): assert once against
        // the default preset. Use a container width that doesn't divide evenly by 12
        // to distinguish round() from floor() and ceil().
        Config::modify()->set(TailwindAdapter::class, 'container_max_width', 1000);
        $adapter = new TailwindAdapter();

        // 1000 * 5 / 12 = 416.666... → round=417, floor=416 (kills floor mutant)
        self::assertSame(417, $adapter->getColumnPixelWidth(5));

        // 1000 * 1 / 12 = 83.333... → round=83, ceil=84 (kills ceil mutant)
        self::assertSame(83, $adapter->getColumnPixelWidth(1));
    }

    // -- Width classes -------------------------------------------------------

    /**
     * Width class for a responsive (non-base) viewport, width 6.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, string}>
     */
    public static function responsiveWidthClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'md', 'col-md-6'];
        yield 'tailwind' => [TailwindAdapter::class, 'md', 'md:col-span-6'];
        yield 'bulma' => [BulmaAdapter::class, 'tablet', 'is-6-tablet'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('responsiveWidthClassProvider')]
    public function testGetWidthClassForResponsiveViewport(string $adapterClass, string $viewport, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getWidthClass($viewport, 6));
    }

    /**
     * Width class for the no-infix base viewport, width 6. Only presets that
     * declare a base viewport reach this branch; Tailwind has none.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, string}>
     */
    public static function baseViewportWidthClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'xs', 'col-6'];
        yield 'bulma' => [BulmaAdapter::class, 'mobile', 'is-6'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('baseViewportWidthClassProvider')]
    public function testGetWidthClassForBaseViewportOmitsInfix(string $adapterClass, string $baseViewport, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getWidthClass($baseViewport, 6));
    }

    // -- Offset classes ------------------------------------------------------

    /**
     * Offset class for a responsive viewport, offset 3. Tailwind's offset_adjustment
     * is 1 (col-start is 1-based) so 3 becomes 4; margin presets keep 3.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, string}>
     */
    public static function responsiveOffsetClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'md', 'offset-md-3'];
        yield 'tailwind' => [TailwindAdapter::class, 'md', 'md:col-start-4'];
        yield 'bulma' => [BulmaAdapter::class, 'tablet', 'is-offset-3-tablet'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('responsiveOffsetClassProvider')]
    public function testGetOffsetClassForResponsiveViewport(string $adapterClass, string $viewport, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getOffsetClass($viewport, 3));
    }

    /**
     * Offset class for the no-infix base viewport, offset 3. Base-viewport presets only.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, string}>
     */
    public static function baseViewportOffsetClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'xs', 'offset-3'];
        yield 'bulma' => [BulmaAdapter::class, 'mobile', 'is-offset-3'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('baseViewportOffsetClassProvider')]
    public function testGetOffsetClassForBaseViewportOmitsInfix(string $adapterClass, string $baseViewport, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getOffsetClass($baseViewport, 3));
    }

    public function testGetOffsetClassAppliesAdjustment(): void
    {
        // Generic +adjustment arithmetic, asserted against the default preset.
        // offset=2, adjustment=1 → 2+1=3. The +→- mutant would yield 1 → 'md:col-start-1'.
        Config::modify()->set(TailwindAdapter::class, 'offset_adjustment', 1);
        $adapter = new TailwindAdapter();

        self::assertSame('md:col-start-3', $adapter->getOffsetClass('md', 2));
    }

    // -- Visibility classes --------------------------------------------------

    /**
     * Visibility classes for a middle (non-first, non-last) viewport. Bulma has no
     * symmetric restore utility (responsive_restore_format = ''), so it emits only
     * the hide class.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, list<string>}>
     */
    public static function middleViewportVisibilityProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'md', ['d-md-none', 'd-lg-block']];
        yield 'tailwind' => [TailwindAdapter::class, 'md', ['md:hidden', 'lg:block']];
        yield 'bulma' => [BulmaAdapter::class, 'tablet', ['is-hidden-tablet-only']];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     * @param list<string> $expected
     */
    #[DataProvider('middleViewportVisibilityProvider')]
    public function testGetVisibilityClassesForMiddleViewport(string $adapterClass, string $viewport, array $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getVisibilityClasses($viewport));
    }

    /**
     * Visibility classes for the last viewport: hide only, no restore.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, list<string>}>
     */
    public static function lastViewportVisibilityProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'xxl', ['d-xxl-none']];
        yield 'tailwind' => [TailwindAdapter::class, '2xl', ['2xl:hidden']];
        yield 'bulma' => [BulmaAdapter::class, 'fullhd', ['is-hidden-fullhd-only']];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     * @param list<string> $expected
     */
    #[DataProvider('lastViewportVisibilityProvider')]
    public function testGetVisibilityClassesForLastViewport(string $adapterClass, string $viewport, array $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getVisibilityClasses($viewport));
    }

    /**
     * Visibility classes for the no-infix base viewport: uses base_hide_class.
     * Base-viewport presets only.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, list<string>}>
     */
    public static function baseViewportVisibilityProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'xs', ['d-none', 'd-sm-block']];
        yield 'bulma' => [BulmaAdapter::class, 'mobile', ['is-hidden-mobile-only']];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     * @param list<string> $expected
     */
    #[DataProvider('baseViewportVisibilityProvider')]
    public function testGetVisibilityClassesForBaseViewport(string $adapterClass, string $baseViewport, array $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getVisibilityClasses($baseViewport));
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('allAdaptersProvider')]
    public function testGetVisibilityClassesThrowsForInvalidViewport(string $adapterClass): void
    {
        $this->expectException(InvalidGridValueException::class);

        (new $adapterClass())->getVisibilityClasses('nonexistent');
    }

    // -- Row, container, title -----------------------------------------------

    /**
     * @return iterable<string, array{class-string<GridAdapter>, string}>
     */
    public static function rowClassesProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'row'];
        yield 'tailwind' => [TailwindAdapter::class, 'grid grid-cols-12'];
        yield 'bulma' => [BulmaAdapter::class, 'columns is-multiline'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('rowClassesProvider')]
    public function testGetRowClasses(string $adapterClass, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getRowClasses());
    }

    /**
     * @return iterable<string, array{class-string<GridAdapter>, string, string}>
     */
    public static function containerClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'container', 'container-fluid'];
        yield 'tailwind' => [TailwindAdapter::class, 'container mx-auto', 'w-full'];
        yield 'bulma' => [BulmaAdapter::class, 'container', 'container is-fluid'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('containerClassProvider')]
    public function testGetContainerClass(string $adapterClass, string $expectedFixed, string $expectedFluid): void
    {
        $adapter = new $adapterClass();

        self::assertSame($expectedFixed, $adapter->getContainerClass(false));
        self::assertSame($expectedFluid, $adapter->getContainerClass(true));
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('allAdaptersProvider')]
    public function testGetTitleClassOptionsReturnsNonEmptyArray(string $adapterClass): void
    {
        $options = (new $adapterClass())->getTitleClassOptions();

        self::assertNotEmpty($options);
        self::assertIsArray($options);
    }

    // -- Base width/offset classes -------------------------------------------

    /**
     * @return iterable<string, array{class-string<GridAdapter>, string}>
     */
    public static function baseWidthClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'col-6'];
        yield 'tailwind' => [TailwindAdapter::class, 'col-span-6'];
        yield 'bulma' => [BulmaAdapter::class, 'is-6'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('baseWidthClassProvider')]
    public function testGetBaseWidthClass(string $adapterClass, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getBaseWidthClass(6));
    }

    /**
     * Base offset class for offset 3. Tailwind adds its offset_adjustment of 1 → col-start-4.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string}>
     */
    public static function baseOffsetClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'offset-3'];
        yield 'tailwind' => [TailwindAdapter::class, 'col-start-4'];
        yield 'bulma' => [BulmaAdapter::class, 'is-offset-3'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('baseOffsetClassProvider')]
    public function testGetBaseOffsetClass(string $adapterClass, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getBaseOffsetClass(3));
    }

    public function testGetBaseOffsetClassAppliesAdjustment(): void
    {
        // Generic +adjustment arithmetic, asserted against the default preset.
        // offset=2, adjustment=1 → 2+1=3. The +→- mutant would yield 1.
        Config::modify()->set(TailwindAdapter::class, 'offset_adjustment', 1);
        $adapter = new TailwindAdapter();

        self::assertSame('col-start-3', $adapter->getBaseOffsetClass(2));
    }

    // -- Offset strategy -----------------------------------------------------

    /**
     * @return iterable<string, array{class-string<GridAdapter>, OffsetStrategy}>
     */
    public static function offsetStrategyProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, OffsetStrategy::Margin];
        yield 'tailwind' => [TailwindAdapter::class, OffsetStrategy::GridPlacement];
        yield 'bulma' => [BulmaAdapter::class, OffsetStrategy::Margin];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('offsetStrategyProvider')]
    public function testGetOffsetStrategy(string $adapterClass, OffsetStrategy $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getOffsetStrategy());
    }

    // -- Content layout: aspect ratio ----------------------------------------

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('allAdaptersProvider')]
    public function testGetAspectRatioClassAutoReturnsNull(string $adapterClass): void
    {
        self::assertNull((new $adapterClass())->getAspectRatioClass(AspectRatio::Auto));
    }

    /**
     * @return iterable<string, array{class-string<GridAdapter>, AspectRatio, string}>
     */
    public static function nonAutoAspectRatioProvider(): iterable
    {
        yield 'bootstrap 1x1' => [BootstrapAdapter::class, AspectRatio::Square, 'ratio ratio-1x1'];
        yield 'bootstrap 4x3' => [BootstrapAdapter::class, AspectRatio::FourByThree, 'ratio ratio-4x3'];
        yield 'bootstrap 16x9' => [BootstrapAdapter::class, AspectRatio::SixteenByNine, 'ratio ratio-16x9'];
        yield 'tailwind 1x1' => [TailwindAdapter::class, AspectRatio::Square, 'aspect-square'];
        yield 'tailwind 4x3' => [TailwindAdapter::class, AspectRatio::FourByThree, 'aspect-[4/3]'];
        yield 'tailwind 16x9' => [TailwindAdapter::class, AspectRatio::SixteenByNine, 'aspect-video'];
        yield 'bulma 1x1' => [BulmaAdapter::class, AspectRatio::Square, 'is-1by1'];
        yield 'bulma 4x3' => [BulmaAdapter::class, AspectRatio::FourByThree, 'is-4by3'];
        yield 'bulma 16x9' => [BulmaAdapter::class, AspectRatio::SixteenByNine, 'is-16by9'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('nonAutoAspectRatioProvider')]
    public function testGetAspectRatioClassNonAutoReturnsString(string $adapterClass, AspectRatio $ratio, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getAspectRatioClass($ratio));
    }

    public function testGetAspectRatioClassThrowsOnMissingConfig(): void
    {
        // Generic guard (missing config entry → throw), asserted against the default preset.
        Config::modify()->set(TailwindAdapter::class, 'aspect_ratio_classes', []);
        $adapter = new TailwindAdapter();

        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessage(TailwindAdapter::class);
        $this->expectExceptionMessage('1x1');

        $adapter->getAspectRatioClass(AspectRatio::Square);
    }

    // -- Content layout: vertical alignment ----------------------------------

    /**
     * @return iterable<string, array{class-string<GridAdapter>, VerticalAlignment, string}>
     */
    public static function verticalAlignmentProvider(): iterable
    {
        yield 'bootstrap top' => [BootstrapAdapter::class, VerticalAlignment::Top, 'align-items-start'];
        yield 'bootstrap center' => [BootstrapAdapter::class, VerticalAlignment::Center, 'align-items-center'];
        yield 'bootstrap bottom' => [BootstrapAdapter::class, VerticalAlignment::Bottom, 'align-items-end'];
        yield 'tailwind top' => [TailwindAdapter::class, VerticalAlignment::Top, 'items-start'];
        yield 'tailwind center' => [TailwindAdapter::class, VerticalAlignment::Center, 'items-center'];
        yield 'tailwind bottom' => [TailwindAdapter::class, VerticalAlignment::Bottom, 'items-end'];
        yield 'bulma top' => [BulmaAdapter::class, VerticalAlignment::Top, 'is-flex-start'];
        yield 'bulma center' => [BulmaAdapter::class, VerticalAlignment::Center, 'is-vcentered'];
        yield 'bulma bottom' => [BulmaAdapter::class, VerticalAlignment::Bottom, 'is-flex-end'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('verticalAlignmentProvider')]
    public function testGetVerticalAlignmentClass(string $adapterClass, VerticalAlignment $alignment, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getVerticalAlignmentClass($alignment));
    }

    // -- Content layout: media/content order ---------------------------------

    /**
     * LastOnDesktop appends the responsive override at each preset's default viewport
     * (Bootstrap md, Tailwind sm, Bulma desktop).
     *
     * @return iterable<string, array{class-string<GridAdapter>, MediaPosition, string, string}>
     */
    public static function orderClassesProvider(): iterable
    {
        yield 'bootstrap First' => [BootstrapAdapter::class, MediaPosition::First, 'order-1', 'order-2'];
        yield 'bootstrap Last' => [BootstrapAdapter::class, MediaPosition::Last, 'order-2', 'order-1'];
        yield 'bootstrap LastOnDesktop' => [BootstrapAdapter::class, MediaPosition::LastOnDesktop, 'order-1 order-md-2', 'order-2 order-md-1'];
        yield 'tailwind First' => [TailwindAdapter::class, MediaPosition::First, 'order-1', 'order-2'];
        yield 'tailwind Last' => [TailwindAdapter::class, MediaPosition::Last, 'order-2', 'order-1'];
        yield 'tailwind LastOnDesktop' => [TailwindAdapter::class, MediaPosition::LastOnDesktop, 'order-1 sm:order-2', 'order-2 sm:order-1'];
        yield 'bulma First' => [BulmaAdapter::class, MediaPosition::First, 'has-order-1', 'has-order-2'];
        yield 'bulma Last' => [BulmaAdapter::class, MediaPosition::Last, 'has-order-2', 'has-order-1'];
        yield 'bulma LastOnDesktop' => [BulmaAdapter::class, MediaPosition::LastOnDesktop, 'has-order-1 has-order-2-desktop', 'has-order-2 has-order-1-desktop'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('orderClassesProvider')]
    public function testGetMediaOrderClasses(string $adapterClass, MediaPosition $position, string $expectedMedia): void
    {
        self::assertSame($expectedMedia, (new $adapterClass())->getMediaOrderClasses($position));
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('orderClassesProvider')]
    public function testGetContentOrderClasses(string $adapterClass, MediaPosition $position, string $_expectedMedia, string $expectedContent): void
    {
        self::assertSame($expectedContent, (new $adapterClass())->getContentOrderClasses($position));
    }

    // -- Content layout: media/content width ---------------------------------

    /**
     * Media/content width classes for 6 content columns, emitted at each preset's
     * default viewport. Media gets the complement (12 - 6 = 6) columns.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string}>
     */
    public static function mediaContentWidthProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'col-md-6'];
        yield 'tailwind' => [TailwindAdapter::class, 'sm:col-span-6'];
        yield 'bulma' => [BulmaAdapter::class, 'is-6-desktop'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('mediaContentWidthProvider')]
    public function testGetMediaWidthClass(string $adapterClass, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getMediaWidthClass(6));
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('mediaContentWidthProvider')]
    public function testGetContentWidthClass(string $adapterClass, string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getContentWidthClass(6));
    }

    // -- Content layout: padding ---------------------------------------------

    /**
     * Directional padding at size 3, emitted at each preset's default viewport.
     *
     * @return iterable<string, array{class-string<GridAdapter>, string, string}>
     */
    public static function paddingClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, 'ps-md-3', 'pe-md-3'];
        yield 'tailwind' => [TailwindAdapter::class, 'sm:pl-3', 'sm:pr-3'];
        yield 'bulma' => [BulmaAdapter::class, 'pl-3-desktop', 'pr-3-desktop'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('paddingClassProvider')]
    public function testGetPaddingClass(string $adapterClass, string $expectedLeft, string $expectedRight): void
    {
        $adapter = new $adapterClass();

        self::assertSame($expectedLeft, $adapter->getPaddingClass('left', 3));
        self::assertSame($expectedRight, $adapter->getPaddingClass('right', 3));
    }

    // -- Content layout: base column class -----------------------------------

    /**
     * Bulma requires a `column` base class on every grid column; Bootstrap and
     * Tailwind need none (null).
     *
     * @return iterable<string, array{class-string<GridAdapter>, string|null}>
     */
    public static function baseColumnClassProvider(): iterable
    {
        yield 'bootstrap' => [BootstrapAdapter::class, null];
        yield 'tailwind' => [TailwindAdapter::class, null];
        yield 'bulma' => [BulmaAdapter::class, 'column'];
    }

    /**
     * @param class-string<GridAdapter> $adapterClass
     */
    #[DataProvider('baseColumnClassProvider')]
    public function testGetBaseColumnClass(string $adapterClass, ?string $expected): void
    {
        self::assertSame($expected, (new $adapterClass())->getBaseColumnClass());
    }

    // -- Constructor validation ----------------------------------------------
    //
    // The constructor's validation lives entirely in the GridAdapter base class and
    // is not framework-specific output, so it is asserted once against the default
    // preset (Tailwind) rather than redundantly across every preset.

    /**
     * Each case sets a single scalar config key to an invalid value that the
     * adapter constructor must reject.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidScalarConfigProvider(): iterable
    {
        yield 'empty viewports' => ['enabled_viewports', []];
        yield 'invalid default viewport' => ['default_viewport', 'invalid'];
        yield 'zero column count' => ['total_columns', 0];
        yield 'zero container max width' => ['container_max_width', 0];
    }

    #[DataProvider('invalidScalarConfigProvider')]
    public function testConstructorThrowsForInvalidScalarConfig(string $configKey, mixed $configValue): void
    {
        Config::modify()->set(TailwindAdapter::class, $configKey, $configValue);

        $this->expectException(InvalidGridValueException::class);

        new TailwindAdapter();
    }

    public function testConstructorThrowsEmptyViewportsMessageForEmptyEnabledViewports(): void
    {
        Config::modify()->set(TailwindAdapter::class, 'enabled_viewports', []);

        $this->expectException(InvalidGridValueException::class);
        // Substring unique to forEmptyViewports(): the downstream forViewport() throw
        // (also InvalidGridValueException) carries "is not a valid breakpoint" instead,
        // so asserting only the class lets the throw-removal mutant survive.
        $this->expectExceptionMessageMatches('/cannot be an empty array/');

        new TailwindAdapter();
    }

    public function testEnabledViewportsResolveInBreakpointOrderNotConfigOrder(): void
    {
        // enabled_viewports supplied out of breakpoint order; the adapter must
        // resolve them in viewport_definitions (ascending breakpoint) order so
        // the cascade and visibility "next viewport" logic stay correct.
        Config::modify()->set(TailwindAdapter::class, 'enabled_viewports', ['md', 'sm', 'lg']);

        $adapter = new TailwindAdapter();

        $keys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $adapter->getViewports(),
        );
        self::assertSame(['sm', 'md', 'lg'], $keys, 'viewports must be breakpoint-ordered, not config-list-ordered');

        // The "next enabled viewport" used for the restore class must follow
        // breakpoint order: sm restores at md (the next enabled key), not at the
        // config-list neighbour.
        $smVisibility = $adapter->getVisibilityClasses('sm');
        self::assertSame(['sm:hidden', 'md:block'], $smVisibility);
    }

    // -- Malformed viewport_definitions rejection ----------------------------

    /**
     * Each case: [viewport_definitions, ?messagePattern]. When the pattern is
     * non-null the detailed message is asserted; otherwise only the exception
     * type is checked.
     *
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function malformedViewportDefinitionsProvider(): iterable
    {
        yield 'non-array value (legacy shape)' => [
            ['md' => 'Medium'],
            '/viewport_definitions/i',
        ];
        yield 'missing label' => [
            ['md' => ['min_width' => 768]],
            '/label/',
        ];
        yield 'missing min_width' => [
            ['md' => ['label' => 'Medium']],
            '/min_width/',
        ];
        yield 'negative min_width' => [
            ['md' => ['label' => 'Medium', 'min_width' => -1]],
            '/min_width|negative/',
        ];
        yield 'non-int min_width' => [
            ['md' => ['label' => 'Medium', 'min_width' => '768']],
            null,
        ];
        yield 'empty label' => [
            ['md' => ['label' => '', 'min_width' => 768]],
            null,
        ];
        // Viewport keys flow into `.grid-<key>` CSS class names in the
        // frontend, so keys with whitespace or special characters would
        // produce invalid selectors. The adapter must reject them.
        yield 'invalid key characters' => [
            ['md dirty' => ['label' => 'Medium', 'min_width' => 768]],
            '/viewport key/i',
        ];
    }

    /**
     * @param array<string, mixed> $definitions
     */
    #[DataProvider('malformedViewportDefinitionsProvider')]
    public function testConstructorRejectsMalformedViewportDefinitions(array $definitions, ?string $messagePattern): void
    {
        Config::modify()->set(TailwindAdapter::class, 'viewport_definitions', $definitions);

        $this->expectException(InvalidGridValueException::class);

        if ($messagePattern !== null) {
            $this->expectExceptionMessageMatches($messagePattern);
        }

        new TailwindAdapter();
    }

    // -- Enabled viewports filtering -----------------------------------------

    public function testEnabledViewportsFiltersCorrectly(): void
    {
        Config::modify()->set(TailwindAdapter::class, 'enabled_viewports', ['sm', 'md', 'lg']);
        // Default viewport must be within the enabled set
        Config::modify()->set(TailwindAdapter::class, 'default_viewport', 'md');

        $filtered = new TailwindAdapter();

        self::assertCount(3, $filtered->getViewports());

        $keys = array_map(
            static fn (Viewport $vp): string => $vp->key,
            $filtered->getViewports(),
        );

        self::assertSame(['sm', 'md', 'lg'], $keys);
    }
}
