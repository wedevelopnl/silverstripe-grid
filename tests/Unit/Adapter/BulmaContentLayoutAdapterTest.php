<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Adapter\BulmaContentLayoutAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(BulmaContentLayoutAdapter::class)]
final class BulmaContentLayoutAdapterTest extends TestCase
{
    private BulmaContentLayoutAdapter $adapter;
    private GridAdapterInterface $gridAdapter;

    #[\Override]
    protected function setUp(): void
    {
        $this->gridAdapter = $this->createMock(GridAdapterInterface::class);
        $this->gridAdapter->method('getDefaultViewport')->willReturn(new Viewport('desktop', 'Desktop'));
        $this->gridAdapter->method('getColumnCount')->willReturn(12);
        $this->gridAdapter->method('getWidthClass')->willReturnCallback(
            static fn (string $viewport, int $width): string => sprintf('is-%d-%s', $width, $viewport),
        );

        $this->adapter = new BulmaContentLayoutAdapter($this->gridAdapter);
    }

    #[DataProvider('aspectRatioProvider')]
    public function testGetAspectRatioClass(AspectRatio $ratio, ?string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getAspectRatioClass($ratio));
    }

    /**
     * @return iterable<string, array{AspectRatio, ?string}>
     */
    public static function aspectRatioProvider(): iterable
    {
        yield 'Auto returns null' => [AspectRatio::Auto, null];
        yield 'Square' => [AspectRatio::Square, 'is-1by1'];
        yield 'FourByThree' => [AspectRatio::FourByThree, 'is-4by3'];
        yield 'SixteenByNine' => [AspectRatio::SixteenByNine, 'is-16by9'];
    }

    #[DataProvider('verticalAlignmentProvider')]
    public function testGetVerticalAlignmentClass(VerticalAlignment $alignment, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getVerticalAlignmentClass($alignment));
    }

    /**
     * @return iterable<string, array{VerticalAlignment, string}>
     */
    public static function verticalAlignmentProvider(): iterable
    {
        yield 'Top' => [VerticalAlignment::Top, 'is-flex-start'];
        yield 'Center' => [VerticalAlignment::Center, 'is-vcentered'];
        yield 'Bottom' => [VerticalAlignment::Bottom, 'is-flex-end'];
    }

    #[DataProvider('mediaOrderProvider')]
    public function testGetMediaOrderClasses(MediaPosition $position, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getMediaOrderClasses($position));
    }

    /**
     * @return iterable<string, array{MediaPosition, string}>
     */
    public static function mediaOrderProvider(): iterable
    {
        yield 'First' => [MediaPosition::First, 'has-order-1'];
        yield 'Last' => [MediaPosition::Last, 'has-order-2'];
        yield 'LastOnDesktop' => [MediaPosition::LastOnDesktop, 'has-order-1 has-order-2-desktop'];
    }

    #[DataProvider('contentOrderProvider')]
    public function testGetContentOrderClasses(MediaPosition $position, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getContentOrderClasses($position));
    }

    /**
     * @return iterable<string, array{MediaPosition, string}>
     */
    public static function contentOrderProvider(): iterable
    {
        yield 'First' => [MediaPosition::First, 'has-order-2'];
        yield 'Last' => [MediaPosition::Last, 'has-order-1'];
        yield 'LastOnDesktop' => [MediaPosition::LastOnDesktop, 'has-order-2 has-order-1-desktop'];
    }

    public function testGetMediaWidthClass(): void
    {
        $this->assertSame('is-4-desktop', $this->adapter->getMediaWidthClass(8));
    }

    public function testGetContentWidthClass(): void
    {
        $this->assertSame('is-8-desktop', $this->adapter->getContentWidthClass(8));
    }

    #[DataProvider('paddingProvider')]
    public function testGetPaddingClass(string $direction, int $size, string $expected): void
    {
        $this->assertSame($expected, $this->adapter->getPaddingClass($direction, $size));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function paddingProvider(): iterable
    {
        yield 'Left padding size 3' => ['left', 3, 'pl-3-desktop'];
        yield 'Right padding size 3' => ['right', 3, 'pr-3-desktop'];
        yield 'Left padding size 5' => ['left', 5, 'pl-5-desktop'];
    }

    public function testGetBaseColumnClassReturnsColumn(): void
    {
        $this->assertSame('column', $this->adapter->getBaseColumnClass());
    }
}
