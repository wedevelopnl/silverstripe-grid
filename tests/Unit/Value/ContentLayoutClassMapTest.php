<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\VerticalAlignment;

#[CoversClass(ContentLayoutClassMap::class)]
final class ContentLayoutClassMapTest extends TestCase
{
    #[DataProvider('factoryAspectRatioProvider')]
    public function testFactoryAspectRatioClasses(string $factory, string $ratioValue, ?string $expected): void
    {
        $map = ContentLayoutClassMap::$factory();

        $this->assertSame($expected, $map->aspectRatioClasses[$ratioValue]);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function factoryAspectRatioProvider(): iterable
    {
        yield 'bootstrap Auto' => ['bootstrap', AspectRatio::Auto->value, null];
        yield 'bootstrap Square' => ['bootstrap', AspectRatio::Square->value, 'ratio ratio-1x1'];
        yield 'bootstrap FourByThree' => ['bootstrap', AspectRatio::FourByThree->value, 'ratio ratio-4x3'];
        yield 'bootstrap SixteenByNine' => ['bootstrap', AspectRatio::SixteenByNine->value, 'ratio ratio-16x9'];

        yield 'tailwind Auto' => ['tailwind', AspectRatio::Auto->value, null];
        yield 'tailwind Square' => ['tailwind', AspectRatio::Square->value, 'aspect-square'];
        yield 'tailwind FourByThree' => ['tailwind', AspectRatio::FourByThree->value, 'aspect-[4/3]'];
        yield 'tailwind SixteenByNine' => ['tailwind', AspectRatio::SixteenByNine->value, 'aspect-video'];

        yield 'bulma Auto' => ['bulma', AspectRatio::Auto->value, null];
        yield 'bulma Square' => ['bulma', AspectRatio::Square->value, 'is-1by1'];
        yield 'bulma FourByThree' => ['bulma', AspectRatio::FourByThree->value, 'is-4by3'];
        yield 'bulma SixteenByNine' => ['bulma', AspectRatio::SixteenByNine->value, 'is-16by9'];
    }

    #[DataProvider('factoryVerticalAlignmentProvider')]
    public function testFactoryVerticalAlignmentClasses(string $factory, string $alignValue, string $expected): void
    {
        $map = ContentLayoutClassMap::$factory();

        $this->assertSame($expected, $map->verticalAlignmentClasses[$alignValue]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function factoryVerticalAlignmentProvider(): iterable
    {
        yield 'bootstrap Top' => ['bootstrap', VerticalAlignment::Top->value, 'align-items-start'];
        yield 'bootstrap Center' => ['bootstrap', VerticalAlignment::Center->value, 'align-items-center'];
        yield 'bootstrap Bottom' => ['bootstrap', VerticalAlignment::Bottom->value, 'align-items-end'];

        yield 'tailwind Top' => ['tailwind', VerticalAlignment::Top->value, 'items-start'];
        yield 'tailwind Center' => ['tailwind', VerticalAlignment::Center->value, 'items-center'];
        yield 'tailwind Bottom' => ['tailwind', VerticalAlignment::Bottom->value, 'items-end'];

        yield 'bulma Top' => ['bulma', VerticalAlignment::Top->value, 'is-flex-start'];
        yield 'bulma Center' => ['bulma', VerticalAlignment::Center->value, 'is-vcentered'];
        yield 'bulma Bottom' => ['bulma', VerticalAlignment::Bottom->value, 'is-flex-end'];
    }

    #[DataProvider('factoryOrderClassesProvider')]
    public function testFactoryOrderClasses(string $factory, string $expectedOrder1, string $expectedOrder2, string $expectedFormat): void
    {
        $map = ContentLayoutClassMap::$factory();

        $this->assertSame($expectedOrder1, $map->orderClass1);
        $this->assertSame($expectedOrder2, $map->orderClass2);
        $this->assertSame($expectedFormat, $map->responsiveOrderFormat);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function factoryOrderClassesProvider(): iterable
    {
        yield 'bootstrap' => ['bootstrap', 'order-1', 'order-2', 'order-%1$s-%2$d'];
        yield 'tailwind' => ['tailwind', 'order-1', 'order-2', '%1$s:order-%2$d'];
        yield 'bulma' => ['bulma', 'has-order-1', 'has-order-2', 'has-order-%2$d-%1$s'];
    }

    #[DataProvider('factoryPaddingProvider')]
    public function testFactoryPaddingMapping(string $factory, string $expectedLeftPrefix, string $expectedRightPrefix, string $expectedFormat): void
    {
        $map = ContentLayoutClassMap::$factory();

        $this->assertSame($expectedLeftPrefix, $map->paddingDirectionMap['left']);
        $this->assertSame($expectedRightPrefix, $map->paddingDirectionMap['right']);
        $this->assertSame($expectedFormat, $map->paddingFormat);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function factoryPaddingProvider(): iterable
    {
        yield 'bootstrap' => ['bootstrap', 'ps', 'pe', '%1$s-%2$s-%3$d'];
        yield 'tailwind' => ['tailwind', 'pl', 'pr', '%2$s:%1$s-%3$d'];
        yield 'bulma' => ['bulma', 'pl', 'pr', '%1$s-%3$d-%2$s'];
    }

    #[DataProvider('factoryBaseColumnClassProvider')]
    public function testFactoryBaseColumnClass(string $factory, ?string $expected): void
    {
        $map = ContentLayoutClassMap::$factory();

        $this->assertSame($expected, $map->baseColumnClass);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function factoryBaseColumnClassProvider(): iterable
    {
        yield 'bootstrap returns null' => ['bootstrap', null];
        yield 'tailwind returns null' => ['tailwind', null];
        yield 'bulma returns column' => ['bulma', 'column'];
    }
}
