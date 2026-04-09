<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettings::class)]
final class GridSettingsTest extends TestCase
{
    public function testInitialCreatesDefaultFromColumnCount(): void
    {
        $settings = GridSettings::initial(12);

        self::assertEquals(ViewportConfig::default(12), $settings->default);
        self::assertSame([], $settings->overrides);
    }

    public function testHasOverrideReturnsFalseWhenAbsent(): void
    {
        $settings = GridSettings::initial(12);

        self::assertFalse($settings->hasOverride('md'));
    }

    public function testHasOverrideReturnsTrueWhenPresent(): void
    {
        $override = new ViewportConfig(6, 0, true);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        self::assertTrue($settings->hasOverride('md'));
    }

    public function testGetOverrideReturnsNullWhenAbsent(): void
    {
        $settings = GridSettings::initial(12);

        self::assertNull($settings->getOverride('md'));
    }

    public function testGetOverrideReturnsConfigWhenPresent(): void
    {
        $override = new ViewportConfig(6, 2, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        self::assertSame($override, $settings->getOverride('md'));
    }

    public function testForViewportReturnsOverrideWhenPresent(): void
    {
        $override = new ViewportConfig(6, 1, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        self::assertSame($override, $settings->forViewport('md'));
    }

    public function testForViewportFallsBackToDefault(): void
    {
        $settings = GridSettings::initial(12);

        self::assertSame($settings->default, $settings->forViewport('lg'));
    }

    public function testWithDefaultReturnsNewInstanceWithNewDefault(): void
    {
        $override = new ViewportConfig(4, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        $newDefault = new ViewportConfig(6, 0, true);
        $updated = $original->withDefault($newDefault);

        self::assertNotSame($original, $updated);
        self::assertSame($newDefault, $updated->default);
        self::assertSame($override, $updated->getOverride('md'));
        // Original unchanged
        self::assertEquals(ViewportConfig::default(12), $original->default);
    }

    public function testWithOverrideAddsNewOverride(): void
    {
        $original = GridSettings::initial(12);
        $override = new ViewportConfig(6, 0, true);

        $updated = $original->withOverride('md', $override);

        self::assertNotSame($original, $updated);
        self::assertSame($override, $updated->getOverride('md'));
        // Original unchanged
        self::assertFalse($original->hasOverride('md'));
    }

    public function testWithOverrideReplacesExistingOverride(): void
    {
        $first = new ViewportConfig(6, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), ['md' => $first]);

        $replacement = new ViewportConfig(4, 2, false);
        $updated = $original->withOverride('md', $replacement);

        self::assertNotSame($original, $updated);
        self::assertSame($replacement, $updated->getOverride('md'));
        // Original unchanged
        self::assertSame($first, $original->getOverride('md'));
    }

    public function testWithOverridePreservesExistingOverrides(): void
    {
        $lgOverride = new ViewportConfig(4, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), ['lg' => $lgOverride]);

        $mdOverride = new ViewportConfig(6, 1, false);
        $updated = $original->withOverride('md', $mdOverride);

        self::assertSame($mdOverride, $updated->getOverride('md'));
        self::assertSame($lgOverride, $updated->getOverride('lg'));
    }

    public function testWithoutOverrideRemovesSpecificOverride(): void
    {
        $mdOverride = new ViewportConfig(6, 0, true);
        $lgOverride = new ViewportConfig(4, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), [
            'md' => $mdOverride,
            'lg' => $lgOverride,
        ]);

        $updated = $original->withoutOverride('md');

        self::assertNotSame($original, $updated);
        self::assertFalse($updated->hasOverride('md'));
        self::assertTrue($updated->hasOverride('lg'));
        // Original unchanged
        self::assertTrue($original->hasOverride('md'));
    }

    public function testWithoutOverrideIsNoOpWhenAbsent(): void
    {
        $original = GridSettings::initial(12);

        $updated = $original->withoutOverride('md');

        self::assertNotSame($original, $updated);
        self::assertSame([], $updated->overrides);
    }

    public function testWithoutOverridesRemovesAll(): void
    {
        $original = new GridSettings(ViewportConfig::default(12), [
            'md' => new ViewportConfig(6, 0, true),
            'lg' => new ViewportConfig(4, 0, false),
        ]);

        $updated = $original->withoutOverrides();

        self::assertNotSame($original, $updated);
        self::assertSame([], $updated->overrides);
        self::assertSame($original->default, $updated->default);
        // Original unchanged
        self::assertCount(2, $original->overrides);
    }

    public function testToArrayReturnsCorrectShape(): void
    {
        $mdOverride = new ViewportConfig(6, 1, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $mdOverride]);

        $result = $settings->toArray();

        self::assertSame([
            'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'overrides' => [
                'md' => ['width' => 6, 'offset' => 1, 'visible' => false],
            ],
        ], $result);
    }

    public function testToArrayWithEmptyOverrides(): void
    {
        $settings = GridSettings::initial(12);

        $result = $settings->toArray();

        self::assertSame([
            'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'overrides' => [],
        ], $result);
    }

    // ─── equals() ─────────────────────────────────────────────

    /**
     * @return iterable<string, array{GridSettings, GridSettings, bool}>
     */
    public static function equalsProvider(): iterable
    {
        $default = new ViewportConfig(6, 0, true);
        $otherDefault = new ViewportConfig(8, 0, true);
        $smVisible = new ViewportConfig(12, 0, true);
        $smHidden = new ViewportConfig(12, 0, false);
        $lgFour = new ViewportConfig(4, 2, false);

        yield 'both empty with same default' => [
            new GridSettings($default),
            new GridSettings($default),
            true,
        ];

        yield 'both empty from initial() factory' => [
            GridSettings::initial(12),
            GridSettings::initial(12),
            true,
        ];

        yield 'identical defaults and overrides' => [
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            true,
        ];

        yield 'identical overrides but order reversed in map' => [
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            new GridSettings($default, ['lg' => $lgFour, 'sm' => $smVisible]),
            true,
        ];

        yield 'different default width' => [
            new GridSettings($default),
            new GridSettings($otherDefault),
            false,
        ];

        yield 'different default offset' => [
            new GridSettings(new ViewportConfig(6, 0, true)),
            new GridSettings(new ViewportConfig(6, 2, true)),
            false,
        ];

        yield 'different default visibility' => [
            new GridSettings(new ViewportConfig(6, 0, true)),
            new GridSettings(new ViewportConfig(6, 0, false)),
            false,
        ];

        yield 'same default but one has an override' => [
            new GridSettings($default),
            new GridSettings($default, ['sm' => $smHidden]),
            false,
        ];

        yield 'same override key with different value (visibility)' => [
            new GridSettings($default, ['sm' => $smVisible]),
            new GridSettings($default, ['sm' => $smHidden]),
            false,
        ];

        yield 'different override keys' => [
            new GridSettings($default, ['sm' => $smVisible]),
            new GridSettings($default, ['lg' => $smVisible]),
            false,
        ];

        yield 'same override count but one key differs' => [
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            new GridSettings($default, ['sm' => $smVisible, 'xl' => $lgFour]),
            false,
        ];

        yield 'one has superset of overrides' => [
            new GridSettings($default, ['sm' => $smVisible]),
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            false,
        ];
    }

    #[DataProvider('equalsProvider')]
    public function testEquals(GridSettings $a, GridSettings $b, bool $expected): void
    {
        self::assertSame($expected, $a->equals($b));
        // Equality must be symmetric.
        self::assertSame($expected, $b->equals($a));
    }

    // ─── Immutability ───────────────────────────────────────────

    public function testImmutabilityAllWithMethodsReturnNewInstances(): void
    {
        $original = new GridSettings(ViewportConfig::default(12), [
            'md' => new ViewportConfig(6, 0, true),
        ]);

        $withDefault = $original->withDefault(new ViewportConfig(8, 0, true));
        $withOverride = $original->withOverride('lg', new ViewportConfig(4, 0, true));
        $withoutOverride = $original->withoutOverride('md');
        $withoutOverrides = $original->withoutOverrides();

        self::assertNotSame($original, $withDefault);
        self::assertNotSame($original, $withOverride);
        self::assertNotSame($original, $withoutOverride);
        self::assertNotSame($original, $withoutOverrides);
    }
}
