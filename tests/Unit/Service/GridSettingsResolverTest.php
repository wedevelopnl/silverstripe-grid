<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Core\Config\ConfigLoader;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Service\GridSettingsResolver;
use WeDevelop\Grid\Tests\Unit\Adapter\ConfigManifestTrait;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsResolver::class)]
final class GridSettingsResolverTest extends TestCase
{
    use ConfigManifestTrait;

    private MemoryConfigCollection $configCollection;

    protected function setUp(): void
    {
        $this->configCollection = new MemoryConfigCollection();
        ConfigLoader::inst()->pushManifest($this->configCollection);
    }

    protected function tearDown(): void
    {
        ConfigLoader::inst()->popManifest();
    }

    // ─── Isolated strategy ──────────────────────────────────────────

    public function testIsolatedNoOverridesAllDefault(): void
    {
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = GridSettings::initial(12);

        $effective = $resolver->resolveEffective($settings);

        foreach ($effective as $config) {
            $this->assertSame(12, $config->width);
            $this->assertSame(0, $config->offset);
            $this->assertTrue($config->visible);
        }
    }

    public function testIsolatedSingleOverrideOnlyAffectsTargetViewport(): void
    {
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(6, 0, false)],
        );

        $effective = $resolver->resolveEffective($settings);

        // md gets the override
        $this->assertSame(6, $effective['md']->width);
        $this->assertFalse($effective['md']->visible);

        // Other viewports get default
        $this->assertSame(12, $effective['xs']->width);
        $this->assertTrue($effective['xs']->visible);
        $this->assertSame(12, $effective['lg']->width);
        $this->assertTrue($effective['lg']->visible);
    }

    public function testIsolatedMultipleOverridesEachIndependent(): void
    {
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            [
                'sm' => new ViewportConfig(6, 1, true),
                'xl' => new ViewportConfig(4, 2, false),
            ],
        );

        $effective = $resolver->resolveEffective($settings);

        $this->assertSame(6, $effective['sm']->width);
        $this->assertSame(1, $effective['sm']->offset);
        $this->assertSame(4, $effective['xl']->width);
        $this->assertFalse($effective['xl']->visible);

        // Non-overridden viewports use default
        $this->assertSame(12, $effective['xs']->width);
        $this->assertSame(12, $effective['md']->width);
        $this->assertSame(12, $effective['lg']->width);
    }

    // ─── Cascade strategy ───────────────────────────────────────────

    public function testCascadeNoOverridesAllDefault(): void
    {
        $this->configCollection->set(BootstrapAdapter::class, 'override_strategy', 'cascade');
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = GridSettings::initial(12);

        $effective = $resolver->resolveEffective($settings);

        foreach ($effective as $config) {
            $this->assertSame(12, $config->width);
        }
    }

    public function testCascadeSingleOverrideAppliesToSmallerViewports(): void
    {
        $this->configCollection->set(BootstrapAdapter::class, 'override_strategy', 'cascade');
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(6, 0, true)],
        );

        $effective = $resolver->resolveEffective($settings);

        // xs, sm, md all get the override (smallest up to md)
        $this->assertSame(6, $effective['xs']->width);
        $this->assertSame(6, $effective['sm']->width);
        $this->assertSame(6, $effective['md']->width);

        // lg, xl, xxl get default
        $this->assertSame(12, $effective['lg']->width);
        $this->assertSame(12, $effective['xl']->width);
        $this->assertSame(12, $effective['xxl']->width);
    }

    public function testCascadeMultipleOverridesClosestWins(): void
    {
        $this->configCollection->set(BootstrapAdapter::class, 'override_strategy', 'cascade');
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            [
                'sm' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(4, 2, false),
            ],
        );

        $effective = $resolver->resolveEffective($settings);

        // xs, sm get sm's override
        $this->assertSame(6, $effective['xs']->width);
        $this->assertSame(6, $effective['sm']->width);

        // md, lg get lg's override (closest at or above)
        $this->assertSame(4, $effective['md']->width);
        $this->assertFalse($effective['md']->visible);
        $this->assertSame(4, $effective['lg']->width);
        $this->assertFalse($effective['lg']->visible);

        // xl, xxl get default
        $this->assertSame(12, $effective['xl']->width);
        $this->assertTrue($effective['xl']->visible);
    }

    public function testCascadeOverrideOnFirstViewport(): void
    {
        $this->configCollection->set(BootstrapAdapter::class, 'override_strategy', 'cascade');
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['xs' => new ViewportConfig(8, 1, true)],
        );

        $effective = $resolver->resolveEffective($settings);

        // Only xs gets the override
        $this->assertSame(8, $effective['xs']->width);

        // All others get default
        $this->assertSame(12, $effective['sm']->width);
        $this->assertSame(12, $effective['md']->width);
    }

    public function testCascadeOverrideOnLastViewport(): void
    {
        $this->configCollection->set(BootstrapAdapter::class, 'override_strategy', 'cascade');
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['xxl' => new ViewportConfig(4, 0, true)],
        );

        $effective = $resolver->resolveEffective($settings);

        // All viewports get the override (smallest up to xxl = all)
        foreach ($effective as $config) {
            $this->assertSame(4, $config->width);
        }
    }

    // ─── Result structure ───────────────────────────────────────────

    public function testResultContainsAllAdapterViewports(): void
    {
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = GridSettings::initial(12);

        $effective = $resolver->resolveEffective($settings);

        $expectedKeys = array_map(
            static fn ($vp) => $vp->key,
            $adapter->getViewports(),
        );

        $this->assertSame($expectedKeys, array_keys($effective));
    }

    public function testCascadeResultKeysMatchAdapterViewportOrder(): void
    {
        $this->configCollection->set(BootstrapAdapter::class, 'override_strategy', 'cascade');
        $adapter = new BootstrapAdapter();
        $resolver = new GridSettingsResolver($adapter);
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['md' => new ViewportConfig(6, 0, true)],
        );

        $effective = $resolver->resolveEffective($settings);

        // Keys must be in adapter viewport order (smallest to largest).
        // Without array_reverse in resolveCascade, keys would be largest to smallest.
        $expectedKeys = array_map(
            static fn ($vp) => $vp->key,
            $adapter->getViewports(),
        );

        $this->assertSame($expectedKeys, array_keys($effective));
    }
}
