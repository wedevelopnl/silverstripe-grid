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

    /**
     * Bulma's complete hide inventory, transcribed from
     * https://bulma.io/documentation/helpers/visibility-helpers/ — note there is
     * no `-only` variant for `mobile` or `fullhd`.
     *
     * @var list<string>
     */
    private const array BULMA_HIDE_CLASSES = [
        'is-hidden',
        'is-hidden-mobile',
        'is-hidden-tablet',
        'is-hidden-tablet-only',
        'is-hidden-touch',
        'is-hidden-desktop',
        'is-hidden-desktop-only',
        'is-hidden-widescreen',
        'is-hidden-widescreen-only',
        'is-hidden-fullhd',
    ];

    public function testHideAtMiddleViewportUsesIsHiddenOnly(): void
    {
        self::assertSame('is-hidden-tablet-only', $this->adapter->getHideClass('tablet'));
    }

    public function testHideAtBaseViewportUsesPlainHidden(): void
    {
        // 'mobile' is Bulma's base viewport and `is-hidden-mobile` already means
        // "up to 768px" — Bulma ships no `-only` variant for it.
        self::assertSame('is-hidden-mobile', $this->adapter->getHideClass('mobile'));
    }

    public function testHideAtLastViewportUsesPlainHidden(): void
    {
        // `is-hidden-fullhd` means "from 1408px", which is all of the largest
        // breakpoint; Bulma ships no `-only` variant for it either.
        self::assertSame('is-hidden-fullhd', $this->adapter->getHideClass('fullhd'));
    }

    public function testEveryHideClassExistsInBulma(): void
    {
        // The per-viewport assertions above pin the two exceptions; this one fails
        // for any viewport whose class the framework does not actually ship, which
        // is what let the invented `-only` variants render as no-ops.
        foreach (['mobile', 'tablet', 'desktop', 'widescreen', 'fullhd'] as $viewport) {
            self::assertContains(
                $this->adapter->getHideClass($viewport),
                self::BULMA_HIDE_CLASSES,
                "Viewport {$viewport} maps to a class Bulma does not ship",
            );
        }
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
