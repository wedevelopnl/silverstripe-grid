<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\GridAdapter;
use WeDevelop\Grid\Exception\InvalidGridValueException;

/**
 * Tests GridAdapter constructor validation using a real preset with
 * intentionally bad config overrides.
 */
#[CoversClass(GridAdapter::class)]
final class GridAdapterValidationTest extends SapphireTest
{
    protected $usesDatabase = false;

    // ─── Viewport validation ────────────────────────────────────────

    public function testEmptyEnabledViewportsThrows(): void
    {
        BootstrapAdapter::config()->set('enabled_viewports', []);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    public function testUnknownEnabledViewportKeyThrows(): void
    {
        BootstrapAdapter::config()->set('enabled_viewports', ['nonexistent']);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    public function testDefaultViewportNotInActiveSetThrows(): void
    {
        BootstrapAdapter::config()->set('enabled_viewports', ['sm', 'md']);
        BootstrapAdapter::config()->set('default_viewport', 'xl');

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    // ─── Column count validation ────────────────────────────────────

    public function testZeroColumnCountThrows(): void
    {
        BootstrapAdapter::config()->set('total_columns', 0);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    public function testNegativeColumnCountThrows(): void
    {
        BootstrapAdapter::config()->set('total_columns', -5);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    // ─── Container max width validation ─────────────────────────────

    public function testZeroContainerMaxWidthThrows(): void
    {
        BootstrapAdapter::config()->set('container_max_width', 0);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    public function testNegativeContainerMaxWidthThrows(): void
    {
        BootstrapAdapter::config()->set('container_max_width', -100);

        $this->expectException(InvalidGridValueException::class);

        new BootstrapAdapter();
    }

    // ─── Config override integration ────────────────────────────────

    public function testCustomColumnCountIsApplied(): void
    {
        BootstrapAdapter::config()->set('total_columns', 16);

        $adapter = new BootstrapAdapter();

        $this->assertSame(16, $adapter->getColumnCount());
    }

    public function testCustomContainerMaxWidthIsApplied(): void
    {
        BootstrapAdapter::config()->set('container_max_width', 1400);

        $adapter = new BootstrapAdapter();

        $this->assertSame(1400, $adapter->getContainerMaxWidth());
    }

    public function testCustomDefaultViewportIsApplied(): void
    {
        BootstrapAdapter::config()->set('default_viewport', 'lg');

        $adapter = new BootstrapAdapter();

        $this->assertSame('lg', $adapter->getDefaultViewport()->key);
    }

    public function testViewportFilteringReducesViewportSet(): void
    {
        BootstrapAdapter::config()->set('enabled_viewports', ['sm', 'md', 'lg']);
        BootstrapAdapter::config()->set('default_viewport', 'sm');

        $adapter = new BootstrapAdapter();
        $viewports = $adapter->getViewports();

        $this->assertCount(3, $viewports);
    }

    public function testRowClassFormatUsesColumnCount(): void
    {
        BootstrapAdapter::config()->set('total_columns', 16);
        BootstrapAdapter::config()->set('row_class_format', 'grid grid-cols-%d');

        $adapter = new BootstrapAdapter();

        $this->assertSame('grid grid-cols-16', $adapter->getRowClasses());
    }
}
