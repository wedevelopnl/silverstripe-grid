<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Service\ColumnClassResolver;
use WeDevelop\Grid\Service\GridSettingsResolver;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Unit tests for ColumnClassResolver — the CSS class emission algorithm.
 *
 * Input is pre-resolved effective ViewportConfig per viewport (from GridSettingsResolver).
 * Tests verify correct CSS output across all three adapters.
 */
#[CoversClass(ColumnClassResolver::class)]
final class ColumnClassResolverTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Resolve effective settings via GridSettingsResolver for convenience.
     *
     * @return array<non-empty-string, ViewportConfig>
     */
    private static function resolve(GridSettings $settings, GridAdapterInterface $adapter): array
    {
        return (new GridSettingsResolver($adapter))->resolveEffective($settings);
    }

    // ─── Empty settings → base width ────────────────────────────────

    /**
     * @param class-string $adapterClass
     */
    #[DataProvider('emptySettingsProvider')]
    public function testEmptySettingsProducesBaseWidthClass(string $adapterClass, string $expected): void
    {
        $adapter = new $adapterClass();
        $effective = self::resolve(GridSettings::initial(12), $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertSame($expected, $classes);
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function emptySettingsProvider(): iterable
    {
        yield 'Bootstrap' => [BootstrapAdapter::class, 'col-12'];
        yield 'Tailwind' => [TailwindAdapter::class, 'sm:col-span-12'];
        yield 'Bulma' => [BulmaAdapter::class, 'is-12'];
    }

    // ─── Width change at mid viewport ───────────────────────────────

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     * @param array<non-empty-string, ViewportConfig> $overrides
     */
    #[DataProvider('widthChangeAtMidViewportProvider')]
    public function testWidthChangeAtMidViewport(string $adapterClass, array $overrides, string $expected): void
    {
        $adapter = new $adapterClass();
        $settings = new GridSettings(ViewportConfig::default(12), $overrides);
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertSame($expected, $classes);
    }

    /**
     * @return iterable<string, array{class-string, array<non-empty-string, ViewportConfig>, string}>
     */
    public static function widthChangeAtMidViewportProvider(): iterable
    {
        // Isolated strategy: override only at target viewport, reset after
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            ['md' => new ViewportConfig(6, 0, true)],
            'col-12 col-md-6 col-lg-12',
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            ['md' => new ViewportConfig(6, 0, true)],
            'sm:col-span-12 md:col-span-6 lg:col-span-12',
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            ['desktop' => new ViewportConfig(6, 0, true)],
            'is-12 is-6-desktop is-12-widescreen',
        ];
    }

    // ─── Multiple width changes ─────────────────────────────────────

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     * @param array<non-empty-string, ViewportConfig> $overrides
     */
    #[DataProvider('multipleWidthChangesProvider')]
    public function testMultipleWidthChanges(string $adapterClass, array $overrides, string $expected): void
    {
        $adapter = new $adapterClass();
        $settings = new GridSettings(ViewportConfig::default(12), $overrides);
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertSame($expected, $classes);
    }

    /**
     * @return iterable<string, array{class-string, array<non-empty-string, ViewportConfig>, string}>
     */
    public static function multipleWidthChangesProvider(): iterable
    {
        // Isolated: each override independent, reset after last override
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            [
                'md' => new ViewportConfig(8, 0, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            'col-12 col-md-8 col-lg-6 col-xl-12',
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            [
                'md' => new ViewportConfig(8, 0, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            'sm:col-span-12 md:col-span-8 lg:col-span-6 xl:col-span-12',
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            [
                'desktop' => new ViewportConfig(8, 0, true),
                'widescreen' => new ViewportConfig(6, 0, true),
            ],
            'is-12 is-8-desktop is-6-widescreen is-12-fullhd',
        ];
    }

    // ─── Offset at mid viewport ─────────────────────────────────────

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     * @param array<non-empty-string, ViewportConfig> $overrides
     * @param list<string> $expectedContains
     */
    #[DataProvider('offsetAtMidViewportProvider')]
    public function testOffsetAtMidViewport(string $adapterClass, array $overrides, array $expectedContains): void
    {
        $adapter = new $adapterClass();
        $settings = new GridSettings(ViewportConfig::default(12), $overrides);
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $classes);
        }
    }

    /**
     * @return iterable<string, array{class-string, array<non-empty-string, ViewportConfig>, list<string>}>
     */
    public static function offsetAtMidViewportProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            ['md' => new ViewportConfig(8, 2, true)],
            ['col-md-8', 'offset-md-2'],
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            ['md' => new ViewportConfig(8, 2, true)],
            ['md:col-span-8', 'md:col-start-3'],
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            ['desktop' => new ViewportConfig(8, 2, true)],
            ['is-8-desktop', 'is-offset-2-desktop'],
        ];
    }

    // ─── Hidden first viewport ──────────────────────────────────────

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     * @param array<non-empty-string, ViewportConfig> $overrides
     * @param list<string> $expectedContains
     */
    #[DataProvider('hiddenFirstViewportProvider')]
    public function testHiddenFirstViewport(string $adapterClass, array $overrides, array $expectedContains): void
    {
        $adapter = new $adapterClass();
        $settings = new GridSettings(ViewportConfig::default(12), $overrides);
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $classes);
        }
    }

    /**
     * @return iterable<string, array{class-string, array<non-empty-string, ViewportConfig>, list<string>}>
     */
    public static function hiddenFirstViewportProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            ['xs' => new ViewportConfig(12, 0, false)],
            ['d-none', 'd-sm-block'],
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            ['sm' => new ViewportConfig(12, 0, false)],
            ['sm:hidden', 'md:block'],
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            ['mobile' => new ViewportConfig(12, 0, false)],
            ['is-hidden-mobile', 'is-block-tablet'],
        ];
    }

    // ─── Hidden mid viewport + restore ──────────────────────────────

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     * @param array<non-empty-string, ViewportConfig> $overrides
     * @param list<string> $expectedContains
     */
    #[DataProvider('hiddenMidViewportProvider')]
    public function testHiddenMidViewportWithRestore(string $adapterClass, array $overrides, array $expectedContains): void
    {
        $adapter = new $adapterClass();
        $settings = new GridSettings(ViewportConfig::default(12), $overrides);
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $classes);
        }
    }

    /**
     * @return iterable<string, array{class-string, array<non-empty-string, ViewportConfig>, list<string>}>
     */
    public static function hiddenMidViewportProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            [
                'md' => new ViewportConfig(8, 0, false),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            ['d-md-none', 'd-lg-block', 'col-lg-6'],
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            [
                'md' => new ViewportConfig(8, 0, false),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            ['md:hidden', 'lg:block', 'lg:col-span-6'],
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            [
                'desktop' => new ViewportConfig(8, 0, false),
                'widescreen' => new ViewportConfig(6, 0, true),
            ],
            ['is-hidden-desktop', 'is-block-widescreen', 'is-6-widescreen'],
        ];
    }

    // ─── Detailed Bootstrap behavior ────────────────────────────────

    public function testIsolatedOverrideEmitsResetAtNextViewport(): void
    {
        $adapter = new BootstrapAdapter();
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(6, 0, true)],
        );
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        // Isolated: lg resets to default, emitting col-lg-12
        $this->assertStringContainsString('col-lg-12', $classes);
        // xl inherits lg's CSS cascade (both are 12), no extra class
        $this->assertStringNotContainsString('col-xl', $classes);
    }

    public function testOffsetResetAfterOverriddenViewport(): void
    {
        $adapter = new BootstrapAdapter();
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(8, 2, true)],
        );
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertStringContainsString('col-md-8', $classes);
        $this->assertStringContainsString('offset-md-2', $classes);
        // Isolated: lg resets to default (offset=0), emitting reset class
        $this->assertStringContainsString('col-lg-12', $classes);
        $this->assertStringContainsString('offset-lg-0', $classes);
    }

    public function testOffsetResetToZeroEmitsExplicitClass(): void
    {
        $adapter = new BootstrapAdapter();
        $settings = new GridSettings(
            ViewportConfig::default(12),
            [
                'md' => new ViewportConfig(8, 2, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
        );
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertStringContainsString('offset-md-2', $classes);
        $this->assertStringContainsString('offset-lg-0', $classes);
    }

    public function testHiddenMidViewportEmitsCorrectPairsDetailed(): void
    {
        $adapter = new BootstrapAdapter();
        $settings = new GridSettings(
            ViewportConfig::default(12),
            [
                'md' => new ViewportConfig(8, 0, false),
                'lg' => new ViewportConfig(6, 0, true),
            ],
        );
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertStringContainsString('col-12', $classes);
        $this->assertStringContainsString('d-md-none', $classes);
        $this->assertStringContainsString('d-lg-block', $classes);
        $this->assertStringContainsString('col-lg-6', $classes);
    }

    public function testIsolatedHiddenViewportWithGapBeforeRestore(): void
    {
        $adapter = new BootstrapAdapter();
        $settings = new GridSettings(
            ViewportConfig::default(12),
            [
                'md' => new ViewportConfig(6, 0, false),
                'xl' => new ViewportConfig(4, 0, true),
            ],
        );
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        // md hidden, lg restores (back to default, visible)
        $this->assertStringContainsString('d-md-none', $classes);
        $this->assertStringContainsString('d-lg-block', $classes);
        // xl has its own override
        $this->assertStringContainsString('col-xl-4', $classes);
    }

    public function testZeroOffsetNotEmittedAtBaseViewport(): void
    {
        $adapter = new BootstrapAdapter();
        $effective = self::resolve(GridSettings::initial(12), $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertStringNotContainsString('offset', $classes);
    }

    public function testFirstViewportAlwaysEmitsWidthClass(): void
    {
        $adapter = new BootstrapAdapter();
        // Default width=12 at base viewport — must always emit width class
        // even though prevWidth (0) differs from 12, the $isFirst flag ensures emission
        $settings = GridSettings::initial(12);
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertStringContainsString('col-12', $classes);
    }

    public function testNonFirstViewportWithSameOffsetDoesNotEmitOffsetClass(): void
    {
        $adapter = new BootstrapAdapter();
        // Set offset=2 at md viewport. At lg, offset cascades back to 0 (default),
        // which differs from prevOffset=2, so offset IS emitted.
        // But at xl, offset=0 same as lg's 0, so no offset class at xl.
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(8, 2, true)],
        );
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        $this->assertStringContainsString('offset-md-2', $classes);
        $this->assertStringContainsString('offset-lg-0', $classes);
        // xl inherits lg's offset (both 0), no offset class emitted
        $this->assertStringNotContainsString('offset-xl', $classes);
    }

    public function testFirstViewportWithPositiveOffsetEmitsOffsetExactlyOnce(): void
    {
        $adapter = new BootstrapAdapter();
        // Override base viewport (xs) with offset > 0
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['xs' => new ViewportConfig(10, 1, true)],
        );
        $effective = self::resolve($settings, $adapter);
        $classes = ColumnClassResolver::resolve($effective, $adapter);

        // First viewport offset > 0 should be emitted exactly once
        $this->assertSame(1, substr_count($classes, 'offset-1'));
    }
}
