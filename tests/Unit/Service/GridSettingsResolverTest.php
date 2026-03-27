<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Service\GridSettingsResolver;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsResolver::class)]
final class GridSettingsResolverTest extends TestCase
{
    // ── Isolated strategy ───────────────────────────────────────

    public function testIsolatedNoOverridesReturnsDefaultForAllViewports(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'isolated');
        $settings = GridSettings::initial(12);

        $result = $resolver->resolveEffective($settings);

        self::assertCount(3, $result);
        foreach ($result as $config) {
            self::assertTrue($config->equals(ViewportConfig::default(12)));
        }
    }

    public function testIsolatedOneOverrideAffectsOnlyThatViewport(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'isolated');
        $override = new ViewportConfig(6, 2, false);
        $settings = GridSettings::initial(12)->withOverride('md', $override);

        $result = $resolver->resolveEffective($settings);

        self::assertTrue($result['xs']->equals(ViewportConfig::default(12)));
        self::assertTrue($result['md']->equals($override));
        self::assertTrue($result['lg']->equals(ViewportConfig::default(12)));
    }

    public function testIsolatedAllOverridesEachViewportGetsItsOwn(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'isolated');
        $xsOverride = new ViewportConfig(12, 0, true);
        $mdOverride = new ViewportConfig(6, 0, true);
        $lgOverride = new ViewportConfig(4, 2, false);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['xs' => $xsOverride, 'md' => $mdOverride, 'lg' => $lgOverride],
        );

        $result = $resolver->resolveEffective($settings);

        self::assertTrue($result['xs']->equals($xsOverride));
        self::assertTrue($result['md']->equals($mdOverride));
        self::assertTrue($result['lg']->equals($lgOverride));
    }

    // ── Cascade strategy ────────────────────────────────────────

    public function testCascadeNoOverridesReturnsDefaultForAllViewports(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'cascade');
        $settings = GridSettings::initial(12);

        $result = $resolver->resolveEffective($settings);

        self::assertCount(3, $result);
        foreach ($result as $config) {
            self::assertTrue($config->equals(ViewportConfig::default(12)));
        }
    }

    public function testCascadeOverrideAtLargestCascadesToAll(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'cascade');
        $lgOverride = new ViewportConfig(4, 1, false);
        $settings = GridSettings::initial(12)->withOverride('lg', $lgOverride);

        $result = $resolver->resolveEffective($settings);

        // lg override cascades down to xs and md
        self::assertTrue($result['xs']->equals($lgOverride));
        self::assertTrue($result['md']->equals($lgOverride));
        self::assertTrue($result['lg']->equals($lgOverride));
    }

    public function testCascadeOverrideAtMiddleCascadesDownOnly(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'cascade');
        $mdOverride = new ViewportConfig(6, 0, true);
        $settings = GridSettings::initial(12)->withOverride('md', $mdOverride);

        $result = $resolver->resolveEffective($settings);

        // md cascades down to xs; lg has no override → gets default
        self::assertTrue($result['xs']->equals($mdOverride));
        self::assertTrue($result['md']->equals($mdOverride));
        self::assertTrue($result['lg']->equals(ViewportConfig::default(12)));
    }

    public function testCascadeOverrideAtSmallestAffectsOnlySmallest(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'cascade');
        $xsOverride = new ViewportConfig(12, 0, false);
        $settings = GridSettings::initial(12)->withOverride('xs', $xsOverride);

        $result = $resolver->resolveEffective($settings);

        self::assertTrue($result['xs']->equals($xsOverride));
        self::assertTrue($result['md']->equals(ViewportConfig::default(12)));
        self::assertTrue($result['lg']->equals(ViewportConfig::default(12)));
    }

    public function testCascadeMultipleOverrides(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'cascade');
        $mdOverride = new ViewportConfig(6, 0, true);
        $lgOverride = new ViewportConfig(4, 2, false);
        $settings = GridSettings::initial(12)
            ->withOverride('md', $mdOverride)
            ->withOverride('lg', $lgOverride);

        $result = $resolver->resolveEffective($settings);

        // xs gets md's override (cascade from md), md gets its own, lg gets its own
        self::assertTrue($result['xs']->equals($mdOverride));
        self::assertTrue($result['md']->equals($mdOverride));
        self::assertTrue($result['lg']->equals($lgOverride));
    }

    // ── Strategy validation ─────────────────────────────────────

    public function testInvalidStrategyThrowsException(): void
    {
        $this->expectException(InvalidGridValueException::class);

        new GridSettingsResolver(new GridAdapterStub(), 'merge');
    }

    // ── Key ordering ────────────────────────────────────────────

    public function testResultKeysMatchAdapterViewportOrder(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'isolated');
        $settings = GridSettings::initial(12);

        $result = $resolver->resolveEffective($settings);

        self::assertSame(['xs', 'md', 'lg'], array_keys($result));
    }

    public function testCascadeResultKeysMatchAdapterViewportOrder(): void
    {
        $resolver = new GridSettingsResolver(new GridAdapterStub(), 'cascade');
        $settings = GridSettings::initial(12);

        $result = $resolver->resolveEffective($settings);

        self::assertSame(['xs', 'md', 'lg'], array_keys($result));
    }
}
