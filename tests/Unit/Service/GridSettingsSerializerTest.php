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

    /**
     * @param array<string, array{width: int, offset: int, visible: bool}> $expectedOverrides
     */
    #[DataProvider('validJsonProvider')]
    public function testFromJsonParsesValidInput(
        string $json,
        int $expectedWidth,
        int $expectedOffset,
        bool $expectedVisible,
        array $expectedOverrides,
    ): void {
        $result = GridSettingsSerializer::fromJson($json);

        self::assertInstanceOf(GridSettings::class, $result);
        self::assertSame($expectedWidth, $result->default->width);
        self::assertSame($expectedOffset, $result->default->offset);
        self::assertSame($expectedVisible, $result->default->visible);
        self::assertCount(count($expectedOverrides), $result->overrides);

        foreach ($expectedOverrides as $viewport => $expected) {
            self::assertTrue($result->hasOverride($viewport), "Missing override: {$viewport}");
            self::assertSame($expected['width'], $result->overrides[$viewport]->width);
            self::assertSame($expected['offset'], $result->overrides[$viewport]->offset);
            self::assertSame($expected['visible'], $result->overrides[$viewport]->visible);
        }
    }

    /**
     * @return iterable<string, array{string, int, int, bool, array<string, array{width: int, offset: int, visible: bool}>}>
     */
    public static function validJsonProvider(): iterable
    {
        yield 'default only' => [
            json_encode(['default' => ['width' => 6, 'offset' => 2, 'visible' => true]], JSON_THROW_ON_ERROR),
            6, 2, true,
            [],
        ];

        yield 'default with overrides' => [
            json_encode([
                'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
                'overrides' => [
                    'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
                    'lg' => ['width' => 4, 'offset' => 2, 'visible' => false],
                ],
            ], JSON_THROW_ON_ERROR),
            12, 0, true,
            ['md' => ['width' => 6, 'offset' => 0, 'visible' => true], 'lg' => ['width' => 4, 'offset' => 2, 'visible' => false]],
        ];

        yield 'malformed overrides skipped' => [
            json_encode([
                'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
                'overrides' => [
                    'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
                    '' => ['width' => 4, 'offset' => 0, 'visible' => true],
                    'lg' => 'not-an-array',
                ],
            ], JSON_THROW_ON_ERROR),
            12, 0, true,
            ['md' => ['width' => 6, 'offset' => 0, 'visible' => true]],
        ];

        yield 'hidden default' => [
            json_encode(['default' => ['width' => 3, 'offset' => 1, 'visible' => false]], JSON_THROW_ON_ERROR),
            3, 1, false,
            [],
        ];
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

    #[DataProvider('deserializeEmptyProvider')]
    public function testDeserializeOverridesReturnsEmptyArray(mixed $input): void
    {
        self::assertSame([], GridSettingsSerializer::deserializeOverrides($input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function deserializeEmptyProvider(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [42];
        yield 'boolean' => [true];
        yield 'invalid json' => ['not json'];
        yield 'empty array' => ['[]'];
        yield 'empty object' => ['{}'];
        yield 'empty string' => [''];
    }

    /**
     * @param array<string, array{width: int, offset: int, visible: bool}> $expectedOverrides
     */
    #[DataProvider('deserializeValidProvider')]
    public function testDeserializeOverridesReturnsViewportConfigs(string $json, array $expectedOverrides): void
    {
        $result = GridSettingsSerializer::deserializeOverrides($json);

        self::assertCount(count($expectedOverrides), $result);

        foreach ($expectedOverrides as $viewport => $expected) {
            self::assertArrayHasKey($viewport, $result);
            self::assertSame($expected['width'], $result[$viewport]->width);
            self::assertSame($expected['offset'], $result[$viewport]->offset);
            self::assertSame($expected['visible'], $result[$viewport]->visible);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, array{width: int, offset: int, visible: bool}>}>
     */
    public static function deserializeValidProvider(): iterable
    {
        yield 'single viewport' => [
            json_encode(['md' => ['width' => 6, 'offset' => 0, 'visible' => true]], JSON_THROW_ON_ERROR),
            ['md' => ['width' => 6, 'offset' => 0, 'visible' => true]],
        ];

        yield 'multiple viewports' => [
            json_encode([
                'sm' => ['width' => 12, 'offset' => 0, 'visible' => true],
                'lg' => ['width' => 4, 'offset' => 2, 'visible' => false],
            ], JSON_THROW_ON_ERROR),
            [
                'sm' => ['width' => 12, 'offset' => 0, 'visible' => true],
                'lg' => ['width' => 4, 'offset' => 2, 'visible' => false],
            ],
        ];

        yield 'invalid entries skipped' => [
            json_encode([
                'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
                '' => ['width' => 4, 'offset' => 0, 'visible' => true],
                'lg' => 'not-array',
            ], JSON_THROW_ON_ERROR),
            ['md' => ['width' => 6, 'offset' => 0, 'visible' => true]],
        ];
    }
}
