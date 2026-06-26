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

        yield 'full complex scenario with visibility and offset changes' => [
            [
                'xs' => new ViewportConfig(12, 0, true),
                'md' => new ViewportConfig(6, 2, false),
                'lg' => new ViewportConfig(8, 0, true),
            ],
            self::threeViewportStub(),
            'col-xs-12 hidden-md visible-lg col-lg-8 offset-lg-0',
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
