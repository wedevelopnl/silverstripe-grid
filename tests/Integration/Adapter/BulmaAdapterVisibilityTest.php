<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\GridAdapter;

#[CoversClass(BulmaAdapter::class)]
#[CoversClass(GridAdapter::class)]
final class BulmaAdapterVisibilityTest extends SapphireTest
{
    protected $usesDatabase = false;

    private BulmaAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new BulmaAdapter();
    }

    public function testHideAtMiddleViewportUsesIsHiddenOnly(): void
    {
        self::assertSame('is-hidden-tablet-only', $this->adapter->getHideClass('tablet'));
    }

    public function testHideAtBaseViewportUsesPlainHidden(): void
    {
        // Bulma defines no `is-hidden-mobile-only`: the mobile range is already
        // bounded above by tablet, so the plain `is-hidden-mobile` class is
        // viewport-scoped. The `-only` variants exist only for the middle ranges.
        self::assertSame('is-hidden-mobile', $this->adapter->getHideClass('mobile'));
    }

    public function testHideAtLastViewportUsesPlainHidden(): void
    {
        // Symmetrically, fullhd is unbounded above, so Bulma defines
        // `is-hidden-fullhd` (no `-only` variant) — already viewport-scoped.
        self::assertSame('is-hidden-fullhd', $this->adapter->getHideClass('fullhd'));
    }

    public function testHasNoRestoreUtility(): void
    {
        // Bulma's -only hide classes are viewport-scoped, so there is no restore
        // utility. Returning null signals the resolver to hide every viewport
        // explicitly rather than relying on an upward cascade.
        foreach (['mobile', 'tablet', 'desktop', 'widescreen', 'fullhd'] as $viewport) {
            self::assertNull($this->adapter->getRestoreClass($viewport));
        }
    }

    public function testHideClassesDoNotContainNonexistentIsBlockUtilities(): void
    {
        foreach (['mobile', 'tablet', 'desktop', 'widescreen', 'fullhd'] as $viewport) {
            $class = $this->adapter->getHideClass($viewport);

            self::assertStringNotContainsString(
                'is-block-',
                $class,
                "Bulma has no is-block-{viewport} utility; got {$class} for viewport {$viewport}",
            );
            self::assertNotSame('', $class, 'Must not emit empty class strings');
        }
    }
}
