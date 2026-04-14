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
        $classes = $this->adapter->getVisibilityClasses('tablet');

        self::assertSame(['is-hidden-tablet-only'], $classes);
    }

    public function testHideAtBaseViewportUsesIsHiddenOnly(): void
    {
        $classes = $this->adapter->getVisibilityClasses('mobile');

        // 'mobile' is Bulma's base viewport. Emitted class must still target only that viewport.
        self::assertSame(['is-hidden-mobile-only'], $classes);
    }

    public function testHideAtLastViewportUsesPlainHidden(): void
    {
        $classes = $this->adapter->getVisibilityClasses('fullhd');

        self::assertSame(['is-hidden-fullhd-only'], $classes);
    }

    public function testEmittedClassesDoNotContainNonexistentIsBlockUtilities(): void
    {
        foreach (['mobile', 'tablet', 'desktop', 'widescreen', 'fullhd'] as $viewport) {
            $classes = $this->adapter->getVisibilityClasses($viewport);

            foreach ($classes as $class) {
                self::assertStringNotContainsString(
                    'is-block-',
                    $class,
                    "Bulma has no is-block-{viewport} utility; got {$class} for viewport {$viewport}",
                );
                self::assertNotSame('', $class, 'Must not emit empty class strings');
            }
        }
    }
}
