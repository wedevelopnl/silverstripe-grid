<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Adapter\ContentLayoutAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(ContentLayoutAdapter::class)]
final class ContentLayoutAdapterTest extends TestCase
{
    private ContentLayoutAdapter $adapter;
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
        $this->gridAdapter->method('getContentLayoutClassMap')->willReturn(ContentLayoutClassMap::bootstrap());

        $this->adapter = new ContentLayoutAdapter($this->gridAdapter);
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

    /**
     * Cross-preset integration: verifies each factory preset produces correct
     * end-to-end output through the adapter, replacing the three deleted
     * per-framework test classes.
     */
    #[DataProvider('presetOutputProvider')]
    public function testPresetOutput(
        ContentLayoutClassMap $classMap,
        Viewport $viewport,
        string $widthFormat,
        AspectRatio $ratio,
        ?string $expectedRatio,
        VerticalAlignment $alignment,
        string $expectedAlignment,
        MediaPosition $position,
        string $expectedMediaOrder,
        string $expectedContentOrder,
        string $paddingDirection,
        int $paddingSize,
        string $expectedPadding,
        ?string $expectedBaseColumnClass,
    ): void {
        $gridAdapter = $this->createMock(GridAdapterInterface::class);
        $gridAdapter->method('getDefaultViewport')->willReturn($viewport);
        $gridAdapter->method('getColumnCount')->willReturn(12);
        $gridAdapter->method('getWidthClass')->willReturnCallback(
            static fn (string $vp, int $width): string => sprintf($widthFormat, $vp, $width),
        );
        $gridAdapter->method('getContentLayoutClassMap')->willReturn($classMap);

        $adapter = new ContentLayoutAdapter($gridAdapter);

        $this->assertSame($expectedRatio, $adapter->getAspectRatioClass($ratio));
        $this->assertSame($expectedAlignment, $adapter->getVerticalAlignmentClass($alignment));
        $this->assertSame($expectedMediaOrder, $adapter->getMediaOrderClasses($position));
        $this->assertSame($expectedContentOrder, $adapter->getContentOrderClasses($position));
        $this->assertSame($expectedPadding, $adapter->getPaddingClass($paddingDirection, $paddingSize));
        $this->assertSame($expectedBaseColumnClass, $adapter->getBaseColumnClass());
    }

    /**
     * @return iterable<string, array{ContentLayoutClassMap, Viewport, string, AspectRatio, ?string, VerticalAlignment, string, MediaPosition, string, string, string, int, string, ?string}>
     */
    public static function presetOutputProvider(): iterable
    {
        yield 'bootstrap LastOnDesktop' => [
            ContentLayoutClassMap::bootstrap(),
            new Viewport('md', 'Medium'),
            'col-%s-%d',
            AspectRatio::Square,
            'ratio ratio-1x1',
            VerticalAlignment::Center,
            'align-items-center',
            MediaPosition::LastOnDesktop,
            'order-1 order-md-2',
            'order-2 order-md-1',
            'left',
            3,
            'ps-md-3',
            null,
        ];

        yield 'tailwind LastOnDesktop' => [
            ContentLayoutClassMap::tailwind(),
            new Viewport('sm', 'Small'),
            '%s:col-span-%d',
            AspectRatio::SixteenByNine,
            'aspect-video',
            VerticalAlignment::Top,
            'items-start',
            MediaPosition::LastOnDesktop,
            'order-1 sm:order-2',
            'order-2 sm:order-1',
            'right',
            3,
            'sm:pr-3',
            null,
        ];

        yield 'bulma LastOnDesktop' => [
            ContentLayoutClassMap::bulma(),
            new Viewport('desktop', 'Desktop'),
            'is-%2$d-%1$s',
            AspectRatio::FourByThree,
            'is-4by3',
            VerticalAlignment::Bottom,
            'is-flex-end',
            MediaPosition::LastOnDesktop,
            'has-order-1 has-order-2-desktop',
            'has-order-2 has-order-1-desktop',
            'left',
            5,
            'pl-5-desktop',
            'column',
        ];

        yield 'bootstrap First' => [
            ContentLayoutClassMap::bootstrap(),
            new Viewport('md', 'Medium'),
            'col-%s-%d',
            AspectRatio::Auto,
            null,
            VerticalAlignment::Top,
            'align-items-start',
            MediaPosition::First,
            'order-1',
            'order-2',
            'right',
            3,
            'pe-md-3',
            null,
        ];

        yield 'tailwind Last' => [
            ContentLayoutClassMap::tailwind(),
            new Viewport('sm', 'Small'),
            '%s:col-span-%d',
            AspectRatio::Square,
            'aspect-square',
            VerticalAlignment::Bottom,
            'items-end',
            MediaPosition::Last,
            'order-2',
            'order-1',
            'left',
            5,
            'sm:pl-5',
            null,
        ];

        yield 'bulma First' => [
            ContentLayoutClassMap::bulma(),
            new Viewport('desktop', 'Desktop'),
            'is-%2$d-%1$s',
            AspectRatio::Square,
            'is-1by1',
            VerticalAlignment::Center,
            'is-vcentered',
            MediaPosition::First,
            'has-order-1',
            'has-order-2',
            'right',
            3,
            'pr-3-desktop',
            'column',
        ];
    }
}
