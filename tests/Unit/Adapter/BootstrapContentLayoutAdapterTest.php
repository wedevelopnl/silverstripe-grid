<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Adapter\BootstrapContentLayoutAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(BootstrapContentLayoutAdapter::class)]
final class BootstrapContentLayoutAdapterTest extends TestCase
{
    private BootstrapContentLayoutAdapter $adapter;
    private GridAdapterInterface $gridAdapter;

    #[\Override]
    protected function setUp(): void
    {
        $this->gridAdapter = $this->createMock(GridAdapterInterface::class);
        $this->gridAdapter->method('getDefaultViewport')->willReturn(new Viewport('md', 'Medium'));
        $this->gridAdapter->method('getColumnCount')->willReturn(12);
        $this->gridAdapter->method('getWidthClass')->willReturnCallback(
            static fn (string $viewport, int $width): string => sprintf('col-%s-%d', $viewport, $width),
        );

        $this->adapter = new BootstrapContentLayoutAdapter($this->gridAdapter);
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
        yield 'Square' => [AspectRatio::Square, 'ratio ratio-1x1'];
        yield 'FourByThree' => [AspectRatio::FourByThree, 'ratio ratio-4x3'];
        yield 'SixteenByNine' => [AspectRatio::SixteenByNine, 'ratio ratio-16x9'];
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
        yield 'Top' => [VerticalAlignment::Top, 'align-items-start'];
        yield 'Center' => [VerticalAlignment::Center, 'align-items-center'];
        yield 'Bottom' => [VerticalAlignment::Bottom, 'align-items-end'];
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
        yield 'First' => [MediaPosition::First, 'order-1'];
        yield 'Last' => [MediaPosition::Last, 'order-2'];
        yield 'LastOnDesktop' => [MediaPosition::LastOnDesktop, 'order-1 order-md-2'];
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
        yield 'First' => [MediaPosition::First, 'order-2'];
        yield 'Last' => [MediaPosition::Last, 'order-1'];
        yield 'LastOnDesktop' => [MediaPosition::LastOnDesktop, 'order-2 order-md-1'];
    }

    public function testGetMediaWidthClass(): void
    {
        $this->assertSame('col-md-4', $this->adapter->getMediaWidthClass(8));
    }

    public function testGetContentWidthClass(): void
    {
        $this->assertSame('col-md-8', $this->adapter->getContentWidthClass(8));
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
        yield 'Left padding size 3' => ['left', 3, 'ps-md-3'];
        yield 'Right padding size 3' => ['right', 3, 'pe-md-3'];
        yield 'Left padding size 5' => ['left', 5, 'ps-md-5'];
    }

    public function testGetBaseColumnClassReturnsNull(): void
    {
        $this->assertNull($this->adapter->getBaseColumnClass());
    }
}
