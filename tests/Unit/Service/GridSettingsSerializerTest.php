<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Service\GridSettingsSerializer;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsSerializer::class)]
final class GridSettingsSerializerTest extends TestCase
{
    // ── fromJson ────────────────────────────────────────────────

    #[DataProvider('nullJsonProvider')]
    public function testFromJsonReturnsNullForInvalidInput(string $json): void
    {
        self::assertNull(GridSettingsSerializer::fromJson($json));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nullJsonProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'empty object' => ['{}'];
        yield 'empty array' => ['[]'];
        yield 'invalid json' => ['not json'];
        yield 'missing default key' => ['{"noDefault":true}'];
        yield 'default not array' => ['{"default":"string"}'];
        yield 'default width zero' => ['{"default":{"width":0,"offset":0,"visible":true}}'];
        yield 'default width negative' => ['{"default":{"width":-1,"offset":0,"visible":true}}'];
        yield 'default width missing' => ['{"default":{"offset":0,"visible":true}}'];
    }

    public function testFromJsonValidDefaultOnly(): void
    {
        $json = json_encode([
            'default' => ['width' => 6, 'offset' => 2, 'visible' => true],
        ], JSON_THROW_ON_ERROR);

        $result = GridSettingsSerializer::fromJson($json);

        self::assertInstanceOf(GridSettings::class, $result);
        self::assertSame(6, $result->default->width);
        self::assertSame(2, $result->default->offset);
        self::assertTrue($result->default->visible);
        self::assertSame([], $result->overrides);
    }

    public function testFromJsonValidWithOverrides(): void
    {
        $json = json_encode([
            'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'overrides' => [
                'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
                'lg' => ['width' => 4, 'offset' => 2, 'visible' => false],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = GridSettingsSerializer::fromJson($json);

        self::assertInstanceOf(GridSettings::class, $result);
        self::assertCount(2, $result->overrides);
        self::assertSame(6, $result->overrides['md']->width);
        self::assertSame(4, $result->overrides['lg']->width);
        self::assertFalse($result->overrides['lg']->visible);
    }

    public function testFromJsonSkipsMalformedOverrideEntries(): void
    {
        $json = json_encode([
            'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'overrides' => [
                'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
                '' => ['width' => 4, 'offset' => 0, 'visible' => true],   // empty key
                'lg' => 'not-an-array',                                     // non-array value
            ],
        ], JSON_THROW_ON_ERROR);

        $result = GridSettingsSerializer::fromJson($json);

        self::assertInstanceOf(GridSettings::class, $result);
        self::assertCount(1, $result->overrides);
        self::assertTrue($result->hasOverride('md'));
    }

    // ── serializeOverrides ──────────────────────────────────────

    public function testSerializeOverridesEmptyReturnsNull(): void
    {
        self::assertNull(GridSettingsSerializer::serializeOverrides([]));
    }

    public function testSerializeOverridesNonEmptyReturnsJson(): void
    {
        $overrides = [
            'md' => new ViewportConfig(6, 0, true),
        ];

        $result = GridSettingsSerializer::serializeOverrides($overrides);

        self::assertIsString($result);
        $decoded = json_decode($result, true);
        self::assertSame(6, $decoded['md']['width']);
        self::assertSame(0, $decoded['md']['offset']);
        self::assertTrue($decoded['md']['visible']);
    }

    public function testSerializeDeserializeRoundTrip(): void
    {
        $overrides = [
            'md' => new ViewportConfig(6, 1, false),
            'lg' => new ViewportConfig(4, 2, true),
        ];

        $json = GridSettingsSerializer::serializeOverrides($overrides);
        self::assertIsString($json);

        $result = GridSettingsSerializer::deserializeOverrides($json);

        self::assertCount(2, $result);
        self::assertTrue($result['md']->equals(new ViewportConfig(6, 1, false)));
        self::assertTrue($result['lg']->equals(new ViewportConfig(4, 2, true)));
    }

    // ── deserializeOverrides ────────────────────────────────────

    public function testDeserializeOverridesNullReturnsEmpty(): void
    {
        self::assertSame([], GridSettingsSerializer::deserializeOverrides(null));
    }

    public function testDeserializeOverridesNonStringReturnsEmpty(): void
    {
        self::assertSame([], GridSettingsSerializer::deserializeOverrides(42));
    }

    public function testDeserializeOverridesInvalidJsonReturnsEmpty(): void
    {
        self::assertSame([], GridSettingsSerializer::deserializeOverrides('not json'));
    }

    public function testDeserializeOverridesEmptyArrayReturnsEmpty(): void
    {
        self::assertSame([], GridSettingsSerializer::deserializeOverrides('[]'));
    }

    public function testDeserializeOverridesValidJson(): void
    {
        $json = json_encode([
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ], JSON_THROW_ON_ERROR);

        $result = GridSettingsSerializer::deserializeOverrides($json);

        self::assertCount(1, $result);
        self::assertArrayHasKey('md', $result);
        self::assertSame(6, $result['md']->width);
    }

    public function testDeserializeOverridesSkipsInvalidEntries(): void
    {
        $json = json_encode([
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
            '' => ['width' => 4, 'offset' => 0, 'visible' => true],
            'lg' => 'not-array',
        ], JSON_THROW_ON_ERROR);

        $result = GridSettingsSerializer::deserializeOverrides($json);

        self::assertCount(1, $result);
        self::assertArrayHasKey('md', $result);
    }
}
