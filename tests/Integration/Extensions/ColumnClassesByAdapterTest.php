<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Model\Column;

/**
 * Verifies the Column cascade class generation produces correct output
 * for each adapter. The cascade logic in getColumnClasses() is adapter-agnostic
 * but the CSS class strings differ per framework.
 */
#[CoversClass(Column::class)]
final class ColumnClassesByAdapterTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    /**
     * @param class-string $adapterClass
     * @param array<string, array{width: int, offset: int, visible: bool}> $settings
     */
    private function getClassesForAdapter(string $adapterClass, array $settings): string
    {
        $column = Column::create();
        $column->gridAdapter = new $adapterClass();
        $column->setGridSettingsData($settings);
        $column->write();

        return $column->getColumnClasses();
    }

    // ─── Empty settings → base width ────────────────────────────────

    /**
     * @param class-string $adapterClass
     */
    #[DataProvider('emptySettingsProvider')]
    public function testEmptySettingsProducesBaseWidthClass(string $adapterClass, string $expected): void
    {
        $classes = $this->getClassesForAdapter($adapterClass, []);

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
        $classes = $this->getClassesForAdapter($adapterClass, $settings);

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
        $classes = $this->getClassesForAdapter($adapterClass, $settings);

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
        $classes = $this->getClassesForAdapter($adapterClass, $settings);

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
        $classes = $this->getClassesForAdapter($adapterClass, $settings);

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
        $classes = $this->getClassesForAdapter($adapterClass, $settings);

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
}
