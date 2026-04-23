<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(ViewportConfig::class)]
final class ViewportConfigTest extends TestCase
{
    public function testConstructorAssignsProperties(): void
    {
        $config = new ViewportConfig(width: 6, offset: 2, visible: false);

        self::assertSame(6, $config->width);
        self::assertSame(2, $config->offset);
        self::assertFalse($config->visible);
    }

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

    public function testFromArrayCreatesInstance(): void
    {
        $config = ViewportConfig::fromArray([
            'width' => 4,
            'offset' => 1,
            'visible' => false,
        ]);

        self::assertSame(4, $config->width);
        self::assertSame(1, $config->offset);
        self::assertFalse($config->visible);
    }

    public function testToArrayReturnsExpectedShape(): void
    {
        $config = new ViewportConfig(width: 8, offset: 3, visible: true);

        self::assertSame(
            ['width' => 8, 'offset' => 3, 'visible' => true],
            $config->toArray(),
        );
    }

    public function testRoundTripFromArrayToArray(): void
    {
        $data = ['width' => 5, 'offset' => 2, 'visible' => false];

        $config = ViewportConfig::fromArray($data);

        self::assertSame($data, $config->toArray());
    }

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
    }

    public function testFromArrayIncludesContextInErrorMessage(): void
    {
        try {
            ViewportConfig::fromArray(['width' => 6, 'offset' => 0], 'overrides["md"]');
            self::fail('Expected InvalidGridValueException');
        } catch (InvalidGridValueException $e) {
            self::assertStringContainsString('overrides["md"]', $e->getMessage());
        }
    }

    public function testJsonEncodeProducesSerializationShape(): void
    {
        $config = new ViewportConfig(width: 4, offset: 1, visible: false);

        self::assertSame(
            '{"width":4,"offset":1,"visible":false}',
            json_encode($config, JSON_THROW_ON_ERROR),
        );
    }

    // ─── mapFromArray ───────────────────────────────────────────

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

    public function testMapFromArraySkipsEmptyKeyAndNonArrayValue(): void
    {
        $map = ViewportConfig::mapFromArray(
            [
                'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
                '' => ['width' => 4, 'offset' => 0, 'visible' => true],
                'lg' => 'not-an-array',
            ],
            'overrides',
        );

        self::assertSame(['md'], array_keys($map));
    }

    public function testMapFromArrayThrowsOnStructurallyMalformedEntry(): void
    {
        $this->expectException(InvalidGridValueException::class);

        ViewportConfig::mapFromArray(
            ['md' => ['width' => 6, 'visible' => true]],
            'overrides',
        );
    }

    public function testMapFromArrayErrorMessageIncludesKeyPath(): void
    {
        try {
            ViewportConfig::mapFromArray(
                ['md' => ['width' => 6, 'visible' => true]],
                'overrides',
            );
            self::fail('Expected InvalidGridValueException');
        } catch (InvalidGridValueException $e) {
            self::assertStringContainsString('overrides["md"]', $e->getMessage());
        }
    }

    public function testMapFromArrayReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], ViewportConfig::mapFromArray([], 'overrides'));
    }

    public function testEqualsReturnsTrueForIdenticalValues(): void
    {
        $first = new ViewportConfig(width: 6, offset: 1, visible: true);
        $second = new ViewportConfig(width: 6, offset: 1, visible: true);

        self::assertTrue($first->equals($second));
    }

    #[DataProvider('unequalConfigProvider')]
    public function testEqualsReturnsFalseWhenAnyFieldDiffers(
        ViewportConfig $first,
        ViewportConfig $second,
    ): void {
        self::assertFalse($first->equals($second));
    }

    /**
     * @return iterable<string, array{ViewportConfig, ViewportConfig}>
     */
    public static function unequalConfigProvider(): iterable
    {
        $base = new ViewportConfig(width: 6, offset: 1, visible: true);

        yield 'different width' => [$base, new ViewportConfig(width: 4, offset: 1, visible: true)];
        yield 'different offset' => [$base, new ViewportConfig(width: 6, offset: 0, visible: true)];
        yield 'different visible' => [$base, new ViewportConfig(width: 6, offset: 1, visible: false)];
    }
}
