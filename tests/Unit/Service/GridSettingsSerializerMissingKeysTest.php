<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Service\GridSettingsSerializer;

/**
 * Ensures that structurally present but malformed viewport payloads raise a
 * domain exception instead of silently coercing or tripping a TypeError.
 *
 * Contract: if a "default" or "overrides[key]" entry is a present array, every
 * required key must exist with the correct scalar type. Absent entries still
 * fall back to the tolerant null/skip paths validated in the sibling test.
 */
#[CoversClass(GridSettingsSerializer::class)]
final class GridSettingsSerializerMissingKeysTest extends TestCase
{
    #[DataProvider('invalidDefaultProvider')]
    public function testFromJsonThrowsOnInvalidDefault(string $json): void
    {
        $this->expectException(InvalidGridValueException::class);
        GridSettingsSerializer::fromJson($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDefaultProvider(): iterable
    {
        yield 'missing offset' => ['{"default":{"width":6,"visible":true}}'];
        yield 'missing visible' => ['{"default":{"width":6,"offset":0}}'];
        yield 'offset wrong type' => ['{"default":{"width":6,"offset":"0","visible":true}}'];
        yield 'visible wrong type' => ['{"default":{"width":6,"offset":0,"visible":"yes"}}'];
        yield 'width wrong type' => ['{"default":{"width":"6","offset":0,"visible":true}}'];
    }

    #[DataProvider('invalidOverrideProvider')]
    public function testFromJsonThrowsOnInvalidOverride(string $json): void
    {
        $this->expectException(InvalidGridValueException::class);
        GridSettingsSerializer::fromJson($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOverrideProvider(): iterable
    {
        $valid = '"default":{"width":12,"offset":0,"visible":true}';

        yield 'override missing width' => ['{' . $valid . ',"overrides":{"md":{"offset":0,"visible":true}}}'];
        yield 'override missing offset' => ['{' . $valid . ',"overrides":{"md":{"width":6,"visible":true}}}'];
        yield 'override missing visible' => ['{' . $valid . ',"overrides":{"md":{"width":6,"offset":0}}}'];
        yield 'override wrong type' => ['{' . $valid . ',"overrides":{"md":{"width":6,"offset":0,"visible":1}}}'];
    }

    #[DataProvider('invalidDeserializeProvider')]
    public function testDeserializeOverridesThrowsOnInvalidEntry(string $json): void
    {
        $this->expectException(InvalidGridValueException::class);
        GridSettingsSerializer::deserializeOverrides($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDeserializeProvider(): iterable
    {
        yield 'missing width' => ['{"md":{"offset":0,"visible":true}}'];
        yield 'missing offset' => ['{"md":{"width":6,"visible":true}}'];
        yield 'missing visible' => ['{"md":{"width":6,"offset":0}}'];
        yield 'wrong type' => ['{"md":{"width":6,"offset":"0","visible":true}}'];
    }
}
