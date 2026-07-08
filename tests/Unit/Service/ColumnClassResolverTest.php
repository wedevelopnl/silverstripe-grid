<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Service\ColumnClassResolver;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\Viewport;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(ColumnClassResolver::class)]
final class ColumnClassResolverTest extends TestCase
{
    private static function oneViewportStub(): GridAdapterStub
    {
        return new GridAdapterStub([
            new Viewport('xs', 'Extra Small', 0),
        ]);
    }

    private static function twoViewportStub(): GridAdapterStub
    {
        return new GridAdapterStub([
            new Viewport('xs', 'Extra Small', 0),
            new Viewport('md', 'Medium', 768),
        ]);
    }

    private static function threeViewportStub(): GridAdapterStub
    {
        return new GridAdapterStub();
    }

    private static function fourViewportStub(): GridAdapterStub
    {
        return new GridAdapterStub([
            new Viewport('xs', 'Extra Small', 0),
            new Viewport('sm', 'Small', 576),
            new Viewport('md', 'Medium', 768),
            new Viewport('lg', 'Large', 992),
        ]);
    }

    /**
     * Bulma-style adapter: hides are scoped to a single viewport, so there is no
     * restore utility. Each hidden viewport must therefore emit its own hide class.
     */
    private static function nonCascadeFourViewportStub(): GridAdapterStub
    {
        return new GridAdapterStub(
            [
                new Viewport('xs', 'Extra Small', 0),
                new Viewport('sm', 'Small', 576),
                new Viewport('md', 'Medium', 768),
                new Viewport('lg', 'Large', 992),
            ],
            cascadeVisibility: false,
        );
    }

    /**
     * @return iterable<string, array{array<string, ViewportConfig>, GridAdapterStub, string}>
     */
    public static function resolveProvider(): iterable
    {
        yield 'single viewport, full width' => [
            ['xs' => new ViewportConfig(12, 0, true)],
            self::oneViewportStub(),
            'col-xs-12',
        ];

        yield 'single viewport with offset' => [
            ['xs' => new ViewportConfig(6, 3, true)],
            self::oneViewportStub(),
            'col-xs-6 offset-xs-3',
        ];

        yield 'same width across viewports is deduplicated' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::threeViewportStub(),
            'col-xs-6',
        ];

        yield 'width change at breakpoint' => [
            [
                'xs' => new ViewportConfig(12, 0, true),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::threeViewportStub(),
            'col-xs-12 col-md-6',
        ];

        yield 'offset change at breakpoint' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'md' => new ViewportConfig(6, 3, true),
                'lg' => new ViewportConfig(6, 3, true),
            ],
            self::threeViewportStub(),
            'col-xs-6 offset-md-3',
        ];

        yield 'offset reset to zero' => [
            [
                'xs' => new ViewportConfig(6, 3, true),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::threeViewportStub(),
            'col-xs-6 offset-xs-3 offset-md-0',
        ];

        yield 'hidden at middle viewport re-emits width after restore' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'md' => new ViewportConfig(6, 0, false),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::threeViewportStub(),
            'col-xs-6 hidden-md visible-lg col-lg-6',
        ];

        yield 'first viewport hidden' => [
            [
                'xs' => new ViewportConfig(6, 0, false),
                'md' => new ViewportConfig(6, 0, true),
            ],
            self::twoViewportStub(),
            'hidden-xs visible-md col-md-6',
        ];

        // Regression: a column hidden at two consecutive viewports must stay hidden
        // at both. On cascade frameworks the single sm hide cascades to md, and the
        // restore is emitted at lg (where it turns visible again) — NOT at md. The
        // previous implementation paired the restore with the positionally-next
        // viewport, which re-showed the column at md.
        yield 'cascade: consecutive hidden viewports stay hidden until restore' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'sm' => new ViewportConfig(6, 0, false),
                'md' => new ViewportConfig(6, 0, false),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::fourViewportStub(),
            'col-xs-6 hidden-sm visible-lg col-lg-6',
        ];

        // Regression: hidden through the final viewport needs no restore at all —
        // the cascade keeps every later viewport hidden.
        yield 'cascade: hidden through last viewport emits no restore' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'sm' => new ViewportConfig(6, 0, true),
                'md' => new ViewportConfig(6, 0, false),
                'lg' => new ViewportConfig(6, 0, false),
            ],
            self::fourViewportStub(),
            'col-xs-6 hidden-md',
        ];

        // Regression: on a per-viewport (non-cascade) framework, each hidden
        // viewport must emit its own hide class, or the run only hides its first one.
        yield 'non-cascade: every hidden viewport emits its own hide class' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'sm' => new ViewportConfig(6, 0, false),
                'md' => new ViewportConfig(6, 0, false),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::nonCascadeFourViewportStub(),
            'col-xs-6 hidden-sm hidden-md col-lg-6',
        ];

        // Regression: an offset configured on a column hidden at the first viewport
        // must still be emitted when the column first becomes visible. The previous
        // implementation tracked the configured (not emitted) offset across hidden
        // viewports, so the sm offset compared equal and was never emitted at all.
        yield 'offset survives a leading hidden run' => [
            [
                'xs' => new ViewportConfig(6, 2, false),
                'sm' => new ViewportConfig(6, 2, true),
                'md' => new ViewportConfig(6, 2, true),
                'lg' => new ViewportConfig(6, 2, true),
            ],
            self::fourViewportStub(),
            'hidden-xs visible-sm col-sm-6 offset-sm-2',
        ];

        // Regression: an offset emitted before a hidden run cascades past it, so the
        // first visible viewport after the run must reset it. The previous
        // implementation compared against the hidden viewport's configured offset
        // and skipped the reset, leaving the stale offset in effect.
        yield 'offset emitted before a hidden run is reset after it' => [
            [
                'xs' => new ViewportConfig(6, 3, true),
                'sm' => new ViewportConfig(6, 0, false),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::fourViewportStub(),
            'col-xs-6 offset-xs-3 hidden-sm visible-md col-md-6 offset-md-0',
        ];

        yield 'offset unchanged across a hidden run is not re-emitted' => [
            [
                'xs' => new ViewportConfig(6, 3, true),
                'sm' => new ViewportConfig(6, 3, false),
                'md' => new ViewportConfig(6, 3, true),
                'lg' => new ViewportConfig(6, 3, true),
            ],
            self::fourViewportStub(),
            'col-xs-6 offset-xs-3 hidden-sm visible-md col-md-6',
        ];

        yield 'cascade: two separate hidden runs' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'sm' => new ViewportConfig(6, 0, false),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, false),
            ],
            self::fourViewportStub(),
            'col-xs-6 hidden-sm visible-md col-md-6 hidden-lg',
        ];

        yield 'cascade: hidden at every viewport emits a single hide' => [
            [
                'xs' => new ViewportConfig(6, 0, false),
                'sm' => new ViewportConfig(6, 0, false),
                'md' => new ViewportConfig(6, 0, false),
                'lg' => new ViewportConfig(6, 0, false),
            ],
            self::fourViewportStub(),
            'hidden-xs',
        ];

        yield 'non-cascade: hidden at every viewport emits one hide each' => [
            [
                'xs' => new ViewportConfig(6, 0, false),
                'sm' => new ViewportConfig(6, 0, false),
                'md' => new ViewportConfig(6, 0, false),
                'lg' => new ViewportConfig(6, 0, false),
            ],
            self::nonCascadeFourViewportStub(),
            'hidden-xs hidden-sm hidden-md hidden-lg',
        ];

        yield 'non-cascade: first viewport hidden' => [
            [
                'xs' => new ViewportConfig(6, 0, false),
                'sm' => new ViewportConfig(6, 0, true),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, true),
            ],
            self::nonCascadeFourViewportStub(),
            'hidden-xs col-sm-6',
        ];

        yield 'non-cascade: last viewport hidden' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'sm' => new ViewportConfig(6, 0, true),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, false),
            ],
            self::nonCascadeFourViewportStub(),
            'col-xs-6 hidden-lg',
        ];

        yield 'hidden at last viewport' => [
            [
                'xs' => new ViewportConfig(6, 0, true),
                'md' => new ViewportConfig(6, 0, true),
                'lg' => new ViewportConfig(6, 0, false),
            ],
            self::threeViewportStub(),
            'col-xs-6 hidden-lg',
        ];

        yield 'zero offset at first viewport is not emitted' => [
            ['xs' => new ViewportConfig(6, 0, true)],
            self::oneViewportStub(),
            'col-xs-6',
        ];

        // The hidden md viewport's configured offset (2) is never emitted, so the
        // zero offset already in effect at lg needs no reset class. (The stale
        // tracker used to emit a redundant offset-lg-0 here.)
        yield 'full complex scenario with visibility and offset changes' => [
            [
                'xs' => new ViewportConfig(12, 0, true),
                'md' => new ViewportConfig(6, 2, false),
                'lg' => new ViewportConfig(8, 0, true),
            ],
            self::threeViewportStub(),
            'col-xs-12 hidden-md visible-lg col-lg-8',
        ];
    }

    #[DataProvider('resolveProvider')]
    public function testResolve(array $effective, GridAdapterStub $adapter, string $expected): void
    {
        self::assertSame($expected, ColumnClassResolver::resolve($effective, $adapter));
    }

    public function testResolveThrowsWhenEffectiveMapOmitsAViewport(): void
    {
        // threeViewportStub() exposes xs, md, lg — supply a map missing 'lg'.
        $effective = [
            'xs' => new ViewportConfig(12, 0, true),
            'md' => new ViewportConfig(6, 0, true),
        ];

        $this->expectException(InvalidGridValueException::class);
        $this->expectExceptionMessage('Viewport key "lg" is not a valid breakpoint.');

        ColumnClassResolver::resolve($effective, self::threeViewportStub());
    }
}
