<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettings::class)]
final class GridSettingsTest extends TestCase
{
    public function testInitialCreatesDefaultFromColumnCount(): void
    {
        $settings = GridSettings::initial(12);

        self::assertEquals(ViewportConfig::default(12), $settings->default);
        self::assertSame([], $settings->overrides);
    }

    public function testHasOverrideReturnsFalseWhenAbsent(): void
    {
        $settings = GridSettings::initial(12);

        self::assertFalse($settings->hasOverride('md'));
    }

    public function testHasOverrideReturnsTrueWhenPresent(): void
    {
        $override = new ViewportConfig(6, 0, true);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        self::assertTrue($settings->hasOverride('md'));
    }

    public function testGetOverrideReturnsNullWhenAbsent(): void
    {
        $settings = GridSettings::initial(12);

        self::assertNull($settings->getOverride('md'));
    }

    public function testGetOverrideReturnsConfigWhenPresent(): void
    {
        $override = new ViewportConfig(6, 2, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        self::assertSame($override, $settings->getOverride('md'));
    }

    public function testForViewportReturnsOverrideWhenPresent(): void
    {
        $override = new ViewportConfig(6, 1, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        self::assertSame($override, $settings->forViewport('md'));
    }

    public function testForViewportFallsBackToDefault(): void
    {
        $settings = GridSettings::initial(12);

        self::assertSame($settings->default, $settings->forViewport('lg'));
    }

    public function testWithDefaultReturnsNewInstanceWithNewDefault(): void
    {
        $override = new ViewportConfig(4, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), ['md' => $override]);

        $newDefault = new ViewportConfig(6, 0, true);
        $updated = $original->withDefault($newDefault);

        self::assertNotSame($original, $updated);
        self::assertSame($newDefault, $updated->default);
        self::assertSame($override, $updated->getOverride('md'));
        // Original unchanged
        self::assertEquals(ViewportConfig::default(12), $original->default);
    }

    public function testWithOverrideAddsNewOverride(): void
    {
        $original = GridSettings::initial(12);
        $override = new ViewportConfig(6, 0, true);

        $updated = $original->withOverride('md', $override);

        self::assertNotSame($original, $updated);
        self::assertSame($override, $updated->getOverride('md'));
        // Original unchanged
        self::assertFalse($original->hasOverride('md'));
    }

    public function testWithOverrideReplacesExistingOverride(): void
    {
        $first = new ViewportConfig(6, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), ['md' => $first]);

        $replacement = new ViewportConfig(4, 2, false);
        $updated = $original->withOverride('md', $replacement);

        self::assertNotSame($original, $updated);
        self::assertSame($replacement, $updated->getOverride('md'));
        // Original unchanged
        self::assertSame($first, $original->getOverride('md'));
    }

    public function testWithOverridePreservesExistingOverrides(): void
    {
        $lgOverride = new ViewportConfig(4, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), ['lg' => $lgOverride]);

        $mdOverride = new ViewportConfig(6, 1, false);
        $updated = $original->withOverride('md', $mdOverride);

        self::assertSame($mdOverride, $updated->getOverride('md'));
        self::assertSame($lgOverride, $updated->getOverride('lg'));
    }

    public function testWithoutOverrideRemovesSpecificOverride(): void
    {
        $mdOverride = new ViewportConfig(6, 0, true);
        $lgOverride = new ViewportConfig(4, 0, true);
        $original = new GridSettings(ViewportConfig::default(12), [
            'md' => $mdOverride,
            'lg' => $lgOverride,
        ]);

        $updated = $original->withoutOverride('md');

        self::assertNotSame($original, $updated);
        self::assertFalse($updated->hasOverride('md'));
        self::assertTrue($updated->hasOverride('lg'));
        // Original unchanged
        self::assertTrue($original->hasOverride('md'));
    }

    public function testWithoutOverrideIsNoOpWhenAbsent(): void
    {
        $original = GridSettings::initial(12);

        $updated = $original->withoutOverride('md');

        self::assertNotSame($original, $updated);
        self::assertSame([], $updated->overrides);
    }

    public function testWithoutOverridesRemovesAll(): void
    {
        $original = new GridSettings(ViewportConfig::default(12), [
            'md' => new ViewportConfig(6, 0, true),
            'lg' => new ViewportConfig(4, 0, false),
        ]);

        $updated = $original->withoutOverrides();

        self::assertNotSame($original, $updated);
        self::assertSame([], $updated->overrides);
        self::assertSame($original->default, $updated->default);
        // Original unchanged
        self::assertCount(2, $original->overrides);
    }

    public function testToArrayReturnsCorrectShape(): void
    {
        $mdOverride = new ViewportConfig(6, 1, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['md' => $mdOverride]);

        $result = $settings->toArray();

        self::assertSame([
            'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'overrides' => [
                'md' => ['width' => 6, 'offset' => 1, 'visible' => false],
            ],
        ], $result);
    }

    public function testToArrayWithEmptyOverrides(): void
    {
        $settings = GridSettings::initial(12);

        $result = $settings->toArray();

        self::assertSame([
            'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'overrides' => [],
        ], $result);
    }

    public function testJsonEncodeProducesSerializationShape(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['md' => new ViewportConfig(6, 1, false)],
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($settings, JSON_THROW_ON_ERROR), true);

        self::assertSame(
            [
                'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
                'overrides' => [
                    'md' => ['width' => 6, 'offset' => 1, 'visible' => false],
                ],
            ],
            $decoded,
        );
    }

    public function testJsonEncodeWithEmptyOverridesEmitsJsonArray(): void
    {
        // PHP encodes an empty associative array as `[]`, not `{}`. Document
        // the shape so API consumers don't get surprised — fromJson accepts
        // both, so round-tripping is unaffected.
        $settings = GridSettings::initial(12);

        self::assertSame(
            '{"default":{"width":12,"offset":0,"visible":true},"overrides":[]}',
            json_encode($settings, JSON_THROW_ON_ERROR),
        );
    }

    public function testJsonEncodeRoundTripsThroughFromJson(): void
    {
        $original = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['md' => new ViewportConfig(6, 1, false), 'lg' => new ViewportConfig(4, 2, true)],
        );

        $roundTripped = GridSettings::fromJson(json_encode($original, JSON_THROW_ON_ERROR));

        self::assertInstanceOf(GridSettings::class, $roundTripped);
        self::assertTrue($original->equals($roundTripped));
    }

    /**
     * @return iterable<string, array{GridSettings, GridSettings, bool}>
     */
    public static function equalsProvider(): iterable
    {
        $default = new ViewportConfig(6, 0, true);
        $otherDefault = new ViewportConfig(8, 0, true);
        $smVisible = new ViewportConfig(12, 0, true);
        $smHidden = new ViewportConfig(12, 0, false);
        $lgFour = new ViewportConfig(4, 2, false);

        yield 'both empty with same default' => [
            new GridSettings($default),
            new GridSettings($default),
            true,
        ];

        yield 'both empty from initial() factory' => [
            GridSettings::initial(12),
            GridSettings::initial(12),
            true,
        ];

        yield 'identical defaults and overrides' => [
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            true,
        ];

        yield 'identical overrides but order reversed in map' => [
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            new GridSettings($default, ['lg' => $lgFour, 'sm' => $smVisible]),
            true,
        ];

        yield 'different default width' => [
            new GridSettings($default),
            new GridSettings($otherDefault),
            false,
        ];

        yield 'different default offset' => [
            new GridSettings(new ViewportConfig(6, 0, true)),
            new GridSettings(new ViewportConfig(6, 2, true)),
            false,
        ];

        yield 'different default visibility' => [
            new GridSettings(new ViewportConfig(6, 0, true)),
            new GridSettings(new ViewportConfig(6, 0, false)),
            false,
        ];

        yield 'same default but one has an override' => [
            new GridSettings($default),
            new GridSettings($default, ['sm' => $smHidden]),
            false,
        ];

        yield 'same override key with different value (visibility)' => [
            new GridSettings($default, ['sm' => $smVisible]),
            new GridSettings($default, ['sm' => $smHidden]),
            false,
        ];

        yield 'different override keys' => [
            new GridSettings($default, ['sm' => $smVisible]),
            new GridSettings($default, ['lg' => $smVisible]),
            false,
        ];

        yield 'same override count but one key differs' => [
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            new GridSettings($default, ['sm' => $smVisible, 'xl' => $lgFour]),
            false,
        ];

        yield 'one has superset of overrides' => [
            new GridSettings($default, ['sm' => $smVisible]),
            new GridSettings($default, ['sm' => $smVisible, 'lg' => $lgFour]),
            false,
        ];
    }

    #[DataProvider('equalsProvider')]
    public function testEquals(GridSettings $a, GridSettings $b, bool $expected): void
    {
        self::assertSame($expected, $a->equals($b));
        // Equality must be symmetric.
        self::assertSame($expected, $b->equals($a));
    }

    #[DataProvider('fromJsonNullProvider')]
    public function testFromJsonReturnsNullForInvalidInput(string $json): void
    {
        self::assertNull(GridSettings::fromJson($json));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fromJsonNullProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'empty object' => ['{}'];
        yield 'empty array' => ['[]'];
        yield 'invalid json' => ['not json'];
        yield 'missing default key' => ['{"noDefault":true}'];
        yield 'default not array' => ['{"default":"string"}'];
        yield 'default width zero' => ['{"default":{"width":0,"offset":0,"visible":true}}'];
        yield 'default width negative' => ['{"default":{"width":-1,"offset":0,"visible":true}}'];
    }

    /**
     * @param array<string, array{width: int, offset: int, visible: bool}> $expectedOverrides
     */
    #[DataProvider('fromJsonValidProvider')]
    public function testFromJsonParsesValidInput(
        string $json,
        int $expectedWidth,
        int $expectedOffset,
        bool $expectedVisible,
        array $expectedOverrides,
    ): void {
        $result = GridSettings::fromJson($json);

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
    public static function fromJsonValidProvider(): iterable
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

    #[DataProvider('fromJsonMalformedDefaultProvider')]
    public function testFromJsonThrowsOnMalformedDefault(string $json): void
    {
        $this->expectException(InvalidGridValueException::class);
        GridSettings::fromJson($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fromJsonMalformedDefaultProvider(): iterable
    {
        yield 'missing offset' => ['{"default":{"width":6,"visible":true}}'];
        yield 'missing visible' => ['{"default":{"width":6,"offset":0}}'];
        yield 'offset wrong type' => ['{"default":{"width":6,"offset":"0","visible":true}}'];
        yield 'visible wrong type' => ['{"default":{"width":6,"offset":0,"visible":"yes"}}'];
        yield 'width wrong type' => ['{"default":{"width":"6","offset":0,"visible":true}}'];
        // Boundary: the tolerant `width <= 0` null-return only fires for ints.
        // A float width falls through to ViewportConfig::fromArray which throws.
        yield 'width as float' => ['{"default":{"width":6.5,"offset":0,"visible":true}}'];
    }

    #[DataProvider('fromJsonMalformedOverrideProvider')]
    public function testFromJsonThrowsOnMalformedOverride(string $json): void
    {
        $this->expectException(InvalidGridValueException::class);
        GridSettings::fromJson($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fromJsonMalformedOverrideProvider(): iterable
    {
        $valid = '"default":{"width":12,"offset":0,"visible":true}';

        yield 'override missing width' => ['{' . $valid . ',"overrides":{"md":{"offset":0,"visible":true}}}'];
        yield 'override missing offset' => ['{' . $valid . ',"overrides":{"md":{"width":6,"visible":true}}}'];
        yield 'override missing visible' => ['{' . $valid . ',"overrides":{"md":{"width":6,"offset":0}}}'];
        yield 'override wrong type' => ['{' . $valid . ',"overrides":{"md":{"width":6,"offset":0,"visible":1}}}'];
    }

    public function testImmutabilityAllWithMethodsReturnNewInstances(): void
    {
        $original = new GridSettings(ViewportConfig::default(12), [
            'md' => new ViewportConfig(6, 0, true),
        ]);

        $withDefault = $original->withDefault(new ViewportConfig(8, 0, true));
        $withOverride = $original->withOverride('lg', new ViewportConfig(4, 0, true));
        $withoutOverride = $original->withoutOverride('md');
        $withoutOverrides = $original->withoutOverrides();

        self::assertNotSame($original, $withDefault);
        self::assertNotSame($original, $withOverride);
        self::assertNotSame($original, $withoutOverride);
        self::assertNotSame($original, $withoutOverrides);
    }
}
