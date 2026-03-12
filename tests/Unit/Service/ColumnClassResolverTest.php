<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Service\ColumnClassResolver;
use WeDevelop\Grid\Tests\Unit\Adapter\ConfigManifestTrait;

/**
 * Unit tests for ColumnClassResolver — the mobile-first cascade algorithm
 * that generates CSS classes from sparse grid settings.
 *
 * Tests all three adapters without database or SilverStripe model dependency.
 */
#[CoversClass(ColumnClassResolver::class)]
final class ColumnClassResolverTest extends TestCase
{
    use ConfigManifestTrait;

    protected function setUp(): void
    {
        $this->pushConfigManifest();
    }

    protected function tearDown(): void
    {
        $this->popConfigManifest();
    }

    // ─── Empty settings → base width ────────────────────────────────

    /**
     * @param class-string $adapterClass
     */
    #[DataProvider('emptySettingsProvider')]
    public function testEmptySettingsProducesBaseWidthClass(string $adapterClass, string $expected): void
    {
        $classes = ColumnClassResolver::resolve([], new $adapterClass());

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
     * @param class-string $adapterClass
     * @param array<string, array{width: int, offset: int, visible: bool}> $settings
     */
    #[DataProvider('widthChangeAtMidViewportProvider')]
    public function testWidthChangeAtMidViewport(string $adapterClass, array $settings, string $expected): void
    {
        $classes = ColumnClassResolver::resolve($settings, new $adapterClass());

        $this->assertSame($expected, $classes);
    }

    /**
     * @return iterable<string, array{class-string, array<string, array{width: int, offset: int, visible: bool}>, string}>
     */
    public static function widthChangeAtMidViewportProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            ['md' => ['width' => 6, 'offset' => 0, 'visible' => true]],
            'col-12 col-md-6',
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            ['md' => ['width' => 6, 'offset' => 0, 'visible' => true]],
            'sm:col-span-12 md:col-span-6',
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            ['desktop' => ['width' => 6, 'offset' => 0, 'visible' => true]],
            'is-12 is-6-desktop',
        ];
    }

    // ─── Multiple width changes ─────────────────────────────────────

    /**
     * @param class-string $adapterClass
     * @param array<string, array{width: int, offset: int, visible: bool}> $settings
     */
    #[DataProvider('multipleWidthChangesProvider')]
    public function testMultipleWidthChanges(string $adapterClass, array $settings, string $expected): void
    {
        $classes = ColumnClassResolver::resolve($settings, new $adapterClass());

        $this->assertSame($expected, $classes);
    }

    /**
     * @return iterable<string, array{class-string, array<string, array{width: int, offset: int, visible: bool}>, string}>
     */
    public static function multipleWidthChangesProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            [
                'md' => ['width' => 8, 'offset' => 0, 'visible' => true],
                'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            'col-12 col-md-8 col-lg-6',
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            [
                'md' => ['width' => 8, 'offset' => 0, 'visible' => true],
                'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            'sm:col-span-12 md:col-span-8 lg:col-span-6',
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            [
                'desktop' => ['width' => 8, 'offset' => 0, 'visible' => true],
                'widescreen' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            'is-12 is-8-desktop is-6-widescreen',
        ];
    }

    // ─── Offset at mid viewport ─────────────────────────────────────

    /**
     * @param class-string $adapterClass
     * @param array<string, array{width: int, offset: int, visible: bool}> $settings
     * @param list<string> $expectedContains
     */
    #[DataProvider('offsetAtMidViewportProvider')]
    public function testOffsetAtMidViewport(string $adapterClass, array $settings, array $expectedContains): void
    {
        $classes = ColumnClassResolver::resolve($settings, new $adapterClass());

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $classes);
        }
    }

    /**
     * @return iterable<string, array{class-string, array<string, array{width: int, offset: int, visible: bool}>, list<string>}>
     */
    public static function offsetAtMidViewportProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            ['md' => ['width' => 8, 'offset' => 2, 'visible' => true]],
            ['col-md-8', 'offset-md-2'],
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            ['md' => ['width' => 8, 'offset' => 2, 'visible' => true]],
            ['md:col-span-8', 'md:col-start-3'],
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            ['desktop' => ['width' => 8, 'offset' => 2, 'visible' => true]],
            ['is-8-desktop', 'is-offset-2-desktop'],
        ];
    }

    // ─── Hidden first viewport ──────────────────────────────────────

    /**
     * @param class-string $adapterClass
     * @param array<string, array{width: int, offset: int, visible: bool}> $settings
     * @param list<string> $expectedContains
     */
    #[DataProvider('hiddenFirstViewportProvider')]
    public function testHiddenFirstViewport(string $adapterClass, array $settings, array $expectedContains): void
    {
        $classes = ColumnClassResolver::resolve($settings, new $adapterClass());

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $classes);
        }
    }

    /**
     * @return iterable<string, array{class-string, array<string, array{width: int, offset: int, visible: bool}>, list<string>}>
     */
    public static function hiddenFirstViewportProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            ['xs' => ['width' => 12, 'offset' => 0, 'visible' => false]],
            ['d-none', 'd-sm-block'],
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            ['sm' => ['width' => 12, 'offset' => 0, 'visible' => false]],
            ['sm:hidden', 'md:block'],
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            ['mobile' => ['width' => 12, 'offset' => 0, 'visible' => false]],
            ['is-hidden-mobile', 'is-block-tablet'],
        ];
    }

    // ─── Hidden mid viewport + restore ──────────────────────────────

    /**
     * @param class-string $adapterClass
     * @param array<string, array{width: int, offset: int, visible: bool}> $settings
     * @param list<string> $expectedContains
     */
    #[DataProvider('hiddenMidViewportProvider')]
    public function testHiddenMidViewportWithRestore(string $adapterClass, array $settings, array $expectedContains): void
    {
        $classes = ColumnClassResolver::resolve($settings, new $adapterClass());

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $classes);
        }
    }

    /**
     * @return iterable<string, array{class-string, array<string, array{width: int, offset: int, visible: bool}>, list<string>}>
     */
    public static function hiddenMidViewportProvider(): iterable
    {
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            [
                'md' => ['width' => 8, 'offset' => 0, 'visible' => false],
                'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            ['d-md-none', 'd-lg-block', 'col-lg-6'],
        ];
        yield 'Tailwind' => [
            TailwindAdapter::class,
            [
                'md' => ['width' => 8, 'offset' => 0, 'visible' => false],
                'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            ['md:hidden', 'lg:block', 'lg:col-span-6'],
        ];
        yield 'Bulma' => [
            BulmaAdapter::class,
            [
                'desktop' => ['width' => 8, 'offset' => 0, 'visible' => false],
                'widescreen' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            ['is-hidden-desktop', 'is-block-widescreen', 'is-6-widescreen'],
        ];
    }

    // ─── Cascade behavior (Bootstrap, detailed) ─────────────────────

    public function testCascadedWidthNotReEmitted(): void
    {
        $classes = ColumnClassResolver::resolve(
            ['md' => ['width' => 6, 'offset' => 0, 'visible' => true]],
            new BootstrapAdapter(),
        );

        // lg, xl, xxl inherit md=6, no extra classes emitted
        $this->assertStringNotContainsString('col-lg', $classes);
        $this->assertStringNotContainsString('col-xl', $classes);
    }

    public function testCascadedOffsetEmittedOnceAndInherited(): void
    {
        $classes = ColumnClassResolver::resolve(
            ['md' => ['width' => 8, 'offset' => 2, 'visible' => true]],
            new BootstrapAdapter(),
        );

        $this->assertStringContainsString('col-md-8', $classes);
        $this->assertStringContainsString('offset-md-2', $classes);
        $this->assertStringNotContainsString('offset-lg', $classes);
    }

    public function testOffsetResetToZeroEmitsExplicitClass(): void
    {
        $classes = ColumnClassResolver::resolve(
            [
                'md' => ['width' => 8, 'offset' => 2, 'visible' => true],
                'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            new BootstrapAdapter(),
        );

        $this->assertStringContainsString('offset-md-2', $classes);
        $this->assertStringContainsString('offset-lg-0', $classes);
    }

    public function testHiddenMidViewportEmitsCorrectPairsDetailed(): void
    {
        $classes = ColumnClassResolver::resolve(
            [
                'md' => ['width' => 8, 'offset' => 0, 'visible' => false],
                'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
            ],
            new BootstrapAdapter(),
        );

        $this->assertStringContainsString('col-12', $classes);
        $this->assertStringContainsString('d-md-none', $classes);
        $this->assertStringContainsString('d-lg-block', $classes);
        $this->assertStringContainsString('col-lg-6', $classes);
    }

    public function testConsecutiveHiddenViewportsDoNotConflict(): void
    {
        $classes = ColumnClassResolver::resolve(
            [
                'md' => ['width' => 6, 'offset' => 0, 'visible' => false],
                'xl' => ['width' => 4, 'offset' => 0, 'visible' => true],
            ],
            new BootstrapAdapter(),
        );

        $this->assertStringContainsString('d-md-none', $classes);
        $this->assertStringContainsString('d-lg-block', $classes);
        $this->assertStringContainsString('col-xl-4', $classes);
    }

    public function testZeroOffsetNotEmittedAtBaseViewport(): void
    {
        $classes = ColumnClassResolver::resolve([], new BootstrapAdapter());

        $this->assertStringNotContainsString('offset', $classes);
    }
}
