<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Core\Config\ConfigLoader;
use WeDevelop\Grid\Adapter\AbstractGridAdapter;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Unit tests for AbstractGridAdapter internals via a minimal concrete subclass.
 *
 * Tests: viewport filtering, column count resolution, default viewport resolution,
 * container max width resolution, and visibility map building.
 */
#[CoversClass(AbstractGridAdapter::class)]
final class AbstractGridAdapterTest extends TestCase
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

    // ─── Default behavior ───────────────────────────────────────────

    public function testDefaultsPreserveAllViewports(): void
    {
        $adapter = new TestableAdapter();

        $keys = array_map(static fn (Viewport $v): string => $v->key, $adapter->getViewports());

        $this->assertSame(['sm', 'md', 'lg'], $keys);
    }

    public function testDefaultColumnCount(): void
    {
        $adapter = new TestableAdapter();

        $this->assertSame(12, $adapter->getColumnCount());
    }

    public function testDefaultViewport(): void
    {
        $adapter = new TestableAdapter();

        $this->assertSame('md', $adapter->getDefaultViewport()->key);
    }

    public function testDefaultContainerMaxWidth(): void
    {
        $adapter = new TestableAdapter();

        $this->assertSame(1320, $adapter->getContainerMaxWidth());
    }

    // ─── Visibility map ─────────────────────────────────────────────

    public function testVisibilityMapNonLastViewportHasTwoClasses(): void
    {
        $adapter = new TestableAdapter();

        $classes = $adapter->getVisibilityClasses('sm');

        $this->assertCount(2, $classes);
        $this->assertSame('hide-sm', $classes[0]);
        $this->assertSame('show-md', $classes[1]);
    }

    public function testVisibilityMapLastViewportHasOneClass(): void
    {
        $adapter = new TestableAdapter();

        $classes = $adapter->getVisibilityClasses('lg');

        $this->assertCount(1, $classes);
        $this->assertSame('hide-lg', $classes[0]);
    }

    public function testVisibilityMapMiddleViewportPointsToNextViewport(): void
    {
        $adapter = new TestableAdapter();

        $classes = $adapter->getVisibilityClasses('md');

        $this->assertSame(['hide-md', 'show-lg'], $classes);
    }

    // ─── Viewport filtering via config ──────────────────────────────

    public function testViewportFilterSubset(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'enabled_viewports', ['sm', 'lg']);
        $this->configCollection->set(TestableAdapter::class, 'default_viewport', 'sm');
        $adapter = new TestableAdapter();

        $keys = array_map(static fn (Viewport $v): string => $v->key, $adapter->getViewports());

        $this->assertSame(['sm', 'lg'], $keys);
    }

    public function testViewportFilterEmptyArrayThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'enabled_viewports', []);

        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }

    public function testViewportFilterUnknownKeyThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'enabled_viewports', ['sm', 'nonexistent']);

        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }

    public function testVisibilityMapUpdatesAfterFiltering(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'enabled_viewports', ['sm', 'lg']);
        $this->configCollection->set(TestableAdapter::class, 'default_viewport', 'sm');
        $adapter = new TestableAdapter();

        // sm is no longer last-before-lg — it jumps straight to lg
        $classes = $adapter->getVisibilityClasses('sm');
        $this->assertSame(['hide-sm', 'show-lg'], $classes);

        // lg is now last
        $classes = $adapter->getVisibilityClasses('lg');
        $this->assertSame(['hide-lg'], $classes);
    }

    // ─── Column count override ──────────────────────────────────────

    public function testColumnCountOverride(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'total_columns', 16);
        $adapter = new TestableAdapter();

        $this->assertSame(16, $adapter->getColumnCount());
    }

    public function testColumnCountZeroThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'total_columns', 0);

        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }

    public function testColumnCountNegativeThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'total_columns', -5);

        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }

    // ─── Container max width override ───────────────────────────────

    public function testContainerMaxWidthOverride(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'container_max_width', 1400);
        $adapter = new TestableAdapter();

        $this->assertSame(1400, $adapter->getContainerMaxWidth());
    }

    public function testContainerMaxWidthZeroThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'container_max_width', 0);

        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }

    public function testContainerMaxWidthNegativeThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'container_max_width', -100);

        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }

    // ─── Default viewport override ──────────────────────────────────

    public function testDefaultViewportOverride(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'default_viewport', 'lg');
        $adapter = new TestableAdapter();

        $this->assertSame('lg', $adapter->getDefaultViewport()->key);
    }

    public function testDefaultViewportNotInActiveSetThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'enabled_viewports', ['sm', 'lg']);
        // default is 'md' which is not in the enabled set, and no override
        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }

    public function testDefaultViewportOverrideNotInActiveSetThrows(): void
    {
        $this->configCollection->set(TestableAdapter::class, 'default_viewport', 'nonexistent');

        $this->expectException(InvalidGridValueException::class);
        new TestableAdapter();
    }
}

/**
 * Minimal concrete adapter for testing AbstractGridAdapter internals.
 */
final class TestableAdapter extends AbstractGridAdapter
{
    public function __construct()
    {
        parent::__construct(
            allViewports: [
                'sm' => new Viewport('sm', 'Small'),
                'md' => new Viewport('md', 'Medium'),
                'lg' => new Viewport('lg', 'Large'),
            ],
            defaultColumns: 12,
            defaultContainerMaxWidth: 1320,
            defaultViewportKey: 'md',
        );
    }

    public function getWidthClass(string $viewport, int $width): string
    {
        return sprintf('%s:col-%d', $viewport, $width);
    }

    public function getOffsetClass(string $viewport, int $offset): string
    {
        return sprintf('%s:offset-%d', $viewport, $offset);
    }

    public function getBaseWidthClass(int $width): string
    {
        return sprintf('col-%d', $width);
    }

    public function getBaseOffsetClass(int $offset): string
    {
        return sprintf('offset-%d', $offset);
    }

    public function getRowClasses(): string
    {
        return 'row';
    }

    public function getContainerClass(bool $fluid): string
    {
        return $fluid ? 'container-fluid' : 'container';
    }

    public function getTitleClassOptions(): array
    {
        return [];
    }

    public function getOffsetStrategy(): OffsetStrategy
    {
        return OffsetStrategy::Margin;
    }

    public function getContentLayoutClassMap(): ContentLayoutClassMap
    {
        return ContentLayoutClassMap::bootstrap();
    }

    protected function formatHideClass(string $viewportKey): string
    {
        return sprintf('hide-%s', $viewportKey);
    }

    protected function formatRestoreClass(string $viewportKey): string
    {
        return sprintf('show-%s', $viewportKey);
    }
}
