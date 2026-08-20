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

    public function testEveryHideClassExistsInBulma(): void
    {
        // The exact per-viewport mappings (including the two `-only` exceptions)
        // are pinned by GridAdapterTest's hideClassProvider; this one fails
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
}
