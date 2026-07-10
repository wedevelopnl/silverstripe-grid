<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(ViewportConfig::class)]
final class ViewportConfigTest extends TestCase
{
    #[DataProvider('defaultColumnCountProvider')]
    public function testDefaultCreatesFullWidthVisibleConfig(int $columnCount): void
    {
        $config = ViewportConfig::default($columnCount);

        self::assertSame($columnCount, $config->width);
        self::assertSame(0, $config->offset);
        self::assertTrue($config->visible);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function defaultColumnCountProvider(): iterable
    {
        yield '12 columns' => [12];
        yield '16 columns' => [16];
        yield '1 column' => [1];
    }

    // ─── fromArray ──────────────────────────────────────────────

    /**
     * @param array{width: int, offset: int, visible: bool} $data
     */
    #[DataProvider('wellFormedPayloadProvider')]
    public function testFromArrayAcceptsWellFormedPayloadAndRoundTrips(array $data): void
    {
        self::assertSame($data, ViewportConfig::fromArray($data)->toArray());
    }

    /**
     * @return iterable<string, array{array{width: int, offset: int, visible: bool}}>
     */
    public static function wellFormedPayloadProvider(): iterable
    {
        yield 'typical' => [['width' => 5, 'offset' => 2, 'visible' => false]];
        // Boundary: width 1 and offset 0 sit exactly on the accepted side of the range guards.
        yield 'smallest legal width and offset' => [['width' => 1, 'offset' => 0, 'visible' => true]];
        yield 'full width' => [['width' => 12, 'offset' => 0, 'visible' => true]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('malformedPayloadProvider')]
    public function testFromArrayThrowsOnMalformedPayload(array $data): void
    {
        $this->expectException(InvalidGridValueException::class);
        ViewportConfig::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedPayloadProvider(): iterable
    {
        yield 'missing width' => [['offset' => 0, 'visible' => true]];
        yield 'missing offset' => [['width' => 6, 'visible' => true]];
        yield 'missing visible' => [['width' => 6, 'offset' => 0]];
        yield 'width wrong type' => [['width' => '6', 'offset' => 0, 'visible' => true]];
        yield 'offset wrong type' => [['width' => 6, 'offset' => '0', 'visible' => true]];
        yield 'visible wrong type' => [['width' => 6, 'offset' => 0, 'visible' => 'yes']];
        yield 'visible as int' => [['width' => 6, 'offset' => 0, 'visible' => 1]];
        yield 'width zero' => [['width' => 0, 'offset' => 0, 'visible' => true]];
        yield 'width negative' => [['width' => -3, 'offset' => 0, 'visible' => true]];
        yield 'offset negative' => [['width' => 6, 'offset' => -2, 'visible' => true]];
    }

    /**
     * Both entry points must name the offending path so a bad override is traceable.
     */
    #[DataProvider('contextPropagationProvider')]
    public function testErrorMessageIncludesTheContextPath(Closure $invoke): void
    {
        try {
            $invoke();
            self::fail('Expected InvalidGridValueException');
        } catch (InvalidGridValueException $e) {
            self::assertStringContainsString('overrides["md"]', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{Closure}>
     */
    public static function contextPropagationProvider(): iterable
    {
        yield 'fromArray' => [
            static fn (): ViewportConfig => ViewportConfig::fromArray(
                ['width' => 6, 'offset' => 0],
                'overrides["md"]',
            ),
        ];
        yield 'mapFromArray' => [
            static fn (): array => ViewportConfig::mapFromArray(
                ['md' => ['width' => 6, 'visible' => true]],
                'overrides',
            ),
        ];
    }

    // ─── serialization ──────────────────────────────────────────

    /**
     * @param array{width: int, offset: int, visible: bool} $expected
     */
    #[DataProvider('wireShapeProvider')]
    public function testToArrayReturnsWireShape(ViewportConfig $config, array $expected): void
    {
        self::assertSame($expected, $config->toArray());
    }

    /**
     * @param array{width: int, offset: int, visible: bool} $expected
     */
    #[DataProvider('wireShapeProvider')]
    public function testJsonEncodeMatchesWireShape(ViewportConfig $config, array $expected): void
    {
        self::assertSame(
            json_encode($expected, JSON_THROW_ON_ERROR),
            json_encode($config, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{ViewportConfig, array{width: int, offset: int, visible: bool}}>
     */
    public static function wireShapeProvider(): iterable
    {
        yield 'visible' => [
            new ViewportConfig(width: 8, offset: 3, visible: true),
            ['width' => 8, 'offset' => 3, 'visible' => true],
        ];
        yield 'hidden' => [
            new ViewportConfig(width: 4, offset: 1, visible: false),
            ['width' => 4, 'offset' => 1, 'visible' => false],
        ];
    }
    public function testMapFromArrayBuildsKeyedViewportConfigs(): void
    {
        $map = ViewportConfig::mapFromArray(
            [
                'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
                'lg' => ['width' => 4, 'offset' => 2, 'visible' => false],
            ],
            'overrides',
        );

        self::assertCount(2, $map);
        self::assertTrue($map['md']->equals(new ViewportConfig(6, 0, true)));
        self::assertTrue($map['lg']->equals(new ViewportConfig(4, 2, false)));
    }

    /**
     * Unusable entries are skipped inline rather than aborting the map.
     *
     * @param array<string, mixed> $input
     * @param list<string> $expectedKeys
     */
    #[DataProvider('mapSkipProvider')]
    public function testMapFromArraySkipsUnusableEntries(array $input, array $expectedKeys): void
    {
        self::assertSame($expectedKeys, array_keys(ViewportConfig::mapFromArray($input, 'overrides')));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function mapSkipProvider(): iterable
    {
        $valid = ['width' => 6, 'offset' => 0, 'visible' => true];

        yield 'empty input' => [[], []];
        yield 'empty key' => [['md' => $valid, '' => $valid], ['md']];
        yield 'non-array value' => [['md' => $valid, 'lg' => 'not-an-array'], ['md']];
        // Ordering the non-array first proves the skip happens inline rather than
        // aborting the loop before the later valid entry is reached.
        yield 'non-array first, later valid entry kept' => [
            ['md' => 'not-an-array', 'lg' => $valid],
            ['lg'],
        ];
    }

    // ─── constructor contract ───────────────────────────────────

    #[DataProvider('degenerateBoundsProvider')]
    public function testConstructorDoesNotThrowOnDegenerateBounds(int $width, int $offset): void
    {
        // Invariant width>=1/offset>=0 is documented, NOT constructor-enforced, so
        // degenerate values must construct without throwing — pinning the
        // degrade-don't-throw contract DBGridSettings::getValue() depends on.
        $config = new ViewportConfig($width, $offset, true);

        self::assertSame($width, $config->width);
        self::assertSame($offset, $config->offset);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function degenerateBoundsProvider(): iterable
    {
        yield 'zero width' => [0, 0];
        yield 'negative width' => [-1, 0];
        yield 'negative offset' => [6, -1];
    }

    // ─── equals ─────────────────────────────────────────────────

    #[DataProvider('equalityProvider')]
    public function testEqualsComparesEveryField(
        ViewportConfig $first,
        ViewportConfig $second,
        bool $expected,
    ): void {
        self::assertSame($expected, $first->equals($second));
    }

    /**
     * @return iterable<string, array{ViewportConfig, ViewportConfig, bool}>
     */
    public static function equalityProvider(): iterable
    {
        $base = new ViewportConfig(width: 6, offset: 1, visible: true);

        yield 'identical values' => [$base, new ViewportConfig(width: 6, offset: 1, visible: true), true];
        yield 'different width' => [$base, new ViewportConfig(width: 4, offset: 1, visible: true), false];
        yield 'different offset' => [$base, new ViewportConfig(width: 6, offset: 0, visible: true), false];
        yield 'different visible' => [$base, new ViewportConfig(width: 6, offset: 1, visible: false), false];
    }
}
