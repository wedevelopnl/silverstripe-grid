<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Service\GridSettingsResolver;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsResolver::class)]
final class GridSettingsResolverTest extends TestCase
{
    private static function vc(int $width, int $offset = 0, bool $visible = true): ViewportConfig
    {
        return new ViewportConfig($width, $offset, $visible);
    }

    /**
     * Each case yields: [settings, expected viewport → config map].
     *
     * @return iterable<string, array{GridSettings, array<string, ViewportConfig>}>
     */
    public static function isolatedProvider(): iterable
    {
        $d = ViewportConfig::default(12);

        yield 'no overrides' => [
            GridSettings::initial(12),
            ['xs' => $d, 'md' => $d, 'lg' => $d],
        ];

        yield 'single override at xs' => [
            GridSettings::initial(12)->withOverride('xs', self::vc(12, 0, false)),
            ['xs' => self::vc(12, 0, false), 'md' => $d, 'lg' => $d],
        ];

        yield 'single override at md' => [
            GridSettings::initial(12)->withOverride('md', self::vc(6, 2, false)),
            ['xs' => $d, 'md' => self::vc(6, 2, false), 'lg' => $d],
        ];

        yield 'single override at lg' => [
            GridSettings::initial(12)->withOverride('lg', self::vc(4, 1, false)),
            ['xs' => $d, 'md' => $d, 'lg' => self::vc(4, 1, false)],
        ];

        yield 'two overrides xs+lg, middle untouched' => [
            GridSettings::initial(12)
                ->withOverride('xs', self::vc(12, 0, false))
                ->withOverride('lg', self::vc(4, 2, false)),
            ['xs' => self::vc(12, 0, false), 'md' => $d, 'lg' => self::vc(4, 2, false)],
        ];

        yield 'two overrides xs+md' => [
            GridSettings::initial(12)
                ->withOverride('xs', self::vc(12, 0, false))
                ->withOverride('md', self::vc(6, 0, true)),
            ['xs' => self::vc(12, 0, false), 'md' => self::vc(6, 0, true), 'lg' => $d],
        ];

        yield 'two overrides md+lg' => [
            GridSettings::initial(12)
                ->withOverride('md', self::vc(6, 0, true))
                ->withOverride('lg', self::vc(4, 2, false)),
            ['xs' => $d, 'md' => self::vc(6, 0, true), 'lg' => self::vc(4, 2, false)],
        ];

        yield 'all three overrides' => [
            GridSettings::initial(12)
                ->withOverride('xs', self::vc(12, 0, true))
                ->withOverride('md', self::vc(6, 0, true))
                ->withOverride('lg', self::vc(4, 2, false)),
            ['xs' => self::vc(12, 0, true), 'md' => self::vc(6, 0, true), 'lg' => self::vc(4, 2, false)],
        ];

        yield 'override for unknown viewport is ignored' => [
            GridSettings::initial(12)->withOverride('xxl', self::vc(3, 0, true)),
            ['xs' => $d, 'md' => $d, 'lg' => $d],
        ];

        yield 'hidden viewport override' => [
            GridSettings::initial(12)->withOverride('md', self::vc(6, 0, false)),
            ['xs' => $d, 'md' => self::vc(6, 0, false), 'lg' => $d],
        ];
    }

    /**
     * @param array<string, ViewportConfig> $expected
     */
    #[DataProvider('isolatedProvider')]
    public function testIsolated(GridSettings $settings, array $expected): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'isolated');
        $result = $resolver->resolveEffective($settings);

        self::assertSame(array_keys($expected), array_keys($result), 'Result keys must match expected viewport order');

        foreach ($expected as $viewport => $expectedConfig) {
            self::assertTrue(
                $result[$viewport]->equals($expectedConfig),
                sprintf('Viewport "%s": expected %s, got %s', $viewport, json_encode($expectedConfig->toArray()), json_encode($result[$viewport]->toArray())),
            );
        }
    }

    /**
     * Each case yields: [settings, expected viewport → config map].
     *
     * @return iterable<string, array{GridSettings, array<string, ViewportConfig>}>
     */
    public static function cascadeProvider(): iterable
    {
        $d = ViewportConfig::default(12);

        yield 'no overrides' => [
            GridSettings::initial(12),
            ['xs' => $d, 'md' => $d, 'lg' => $d],
        ];

        yield 'override at lg cascades to all' => [
            GridSettings::initial(12)->withOverride('lg', self::vc(4, 1, false)),
            ['xs' => self::vc(4, 1, false), 'md' => self::vc(4, 1, false), 'lg' => self::vc(4, 1, false)],
        ];

        yield 'override at md cascades down only' => [
            GridSettings::initial(12)->withOverride('md', self::vc(6, 0, true)),
            ['xs' => self::vc(6, 0, true), 'md' => self::vc(6, 0, true), 'lg' => $d],
        ];

        yield 'override at xs affects only xs' => [
            GridSettings::initial(12)->withOverride('xs', self::vc(12, 0, false)),
            ['xs' => self::vc(12, 0, false), 'md' => $d, 'lg' => $d],
        ];

        yield 'two overrides md+lg' => [
            GridSettings::initial(12)
                ->withOverride('md', self::vc(6, 0, true))
                ->withOverride('lg', self::vc(4, 2, false)),
            ['xs' => self::vc(6, 0, true), 'md' => self::vc(6, 0, true), 'lg' => self::vc(4, 2, false)],
        ];

        yield 'two overrides xs+lg, md inherits from lg' => [
            GridSettings::initial(12)
                ->withOverride('xs', self::vc(12, 0, false))
                ->withOverride('lg', self::vc(4, 1, true)),
            ['xs' => self::vc(12, 0, false), 'md' => self::vc(4, 1, true), 'lg' => self::vc(4, 1, true)],
        ];

        yield 'two overrides xs+md' => [
            GridSettings::initial(12)
                ->withOverride('xs', self::vc(12, 0, false))
                ->withOverride('md', self::vc(6, 0, true)),
            ['xs' => self::vc(12, 0, false), 'md' => self::vc(6, 0, true), 'lg' => $d],
        ];

        yield 'all three overrides' => [
            GridSettings::initial(12)
                ->withOverride('xs', self::vc(12, 0, true))
                ->withOverride('md', self::vc(6, 0, true))
                ->withOverride('lg', self::vc(4, 2, false)),
            ['xs' => self::vc(12, 0, true), 'md' => self::vc(6, 0, true), 'lg' => self::vc(4, 2, false)],
        ];

        yield 'override for unknown viewport is ignored' => [
            GridSettings::initial(12)->withOverride('xxl', self::vc(3, 0, true)),
            ['xs' => $d, 'md' => $d, 'lg' => $d],
        ];

        yield 'hidden viewport at lg cascades down' => [
            GridSettings::initial(12)->withOverride('lg', self::vc(4, 0, false)),
            ['xs' => self::vc(4, 0, false), 'md' => self::vc(4, 0, false), 'lg' => self::vc(4, 0, false)],
        ];

        yield 'md override does not leak upward past lg override' => [
            GridSettings::initial(12)
                ->withOverride('md', self::vc(6, 0, true))
                ->withOverride('lg', self::vc(4, 2, false)),
            ['xs' => self::vc(6, 0, true), 'md' => self::vc(6, 0, true), 'lg' => self::vc(4, 2, false)],
        ];
    }

    /**
     * @param array<string, ViewportConfig> $expected
     */
    #[DataProvider('cascadeProvider')]
    public function testCascade(GridSettings $settings, array $expected): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'cascade');
        $result = $resolver->resolveEffective($settings);

        self::assertSame(array_keys($expected), array_keys($result), 'Result keys must match expected viewport order');

        foreach ($expected as $viewport => $expectedConfig) {
            self::assertTrue(
                $result[$viewport]->equals($expectedConfig),
                sprintf('Viewport "%s": expected %s, got %s', $viewport, json_encode($expectedConfig->toArray()), json_encode($result[$viewport]->toArray())),
            );
        }
    }

    public function testInvalidStrategyThrowsException(): void
    {
        $this->expectException(InvalidGridValueException::class);

        new GridSettingsResolver(new GridAdapterStub(), 'merge');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function strategyProvider(): iterable
    {
        yield 'isolated' => ['isolated'];
        yield 'cascade' => ['cascade'];
    }

    #[DataProvider('strategyProvider')]
    public function testResultKeysMatchAdapterViewportOrder(string $strategy): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), $strategy);
        $result = $resolver->resolveEffective(GridSettings::initial(12));

        self::assertSame(['xs', 'md', 'lg'], array_keys($result));
    }
}
