<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(Viewport::class)]
final class ViewportTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $vp = new Viewport(key: 'md', label: 'Medium', minWidth: 768);

        self::assertSame('md', $vp->key);
        self::assertSame('Medium', $vp->label);
        self::assertSame(768, $vp->minWidth);
    }

    public function testMobileFirstViewportAcceptsZeroMinWidth(): void
    {
        $vp = new Viewport(key: 'xs', label: 'Extra Small', minWidth: 0);

        self::assertSame(0, $vp->minWidth);
    }

    #[DataProvider('minWidthProvider')]
    public function testMinWidthIsStoredVerbatim(int $minWidth): void
    {
        $vp = new Viewport(key: 'bp', label: 'Breakpoint', minWidth: $minWidth);

        self::assertSame($minWidth, $vp->minWidth);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function minWidthProvider(): iterable
    {
        yield 'zero'              => [0];
        yield 'bootstrap sm'      => [576];
        yield 'bootstrap md'      => [768];
        yield 'tailwind 2xl'      => [1536];
        yield 'bulma fullhd'      => [1408];
    }
}
