<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Validation\GridSettingsFieldValidator;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsFieldValidator::class)]
final class GridSettingsFieldValidatorTest extends SapphireTest
{
    private const COLUMN_COUNT = 12;

    #[DataProvider('defaultViewportProvider')]
    public function testDefaultViewportValidation(int $width, int $offset, bool $valid, int $expectedMessageCount): void
    {
        $settings = new GridSettings(new ViewportConfig($width, $offset, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertSame($valid, $result->isValid());
        self::assertCount($expectedMessageCount, $result->getMessages());
    }

    /**
     * @return iterable<string, array{int, int, bool, int}>
     */
    public static function defaultViewportProvider(): iterable
    {
        yield 'valid width and offset pass' => [6, 0, true, 0];

        // width=13 > 12 triggers width error, and 13+0=13 > 12 triggers sum error
        yield 'width exceeding column count fails' => [13, 0, false, 2];

        // offset=12 >= 12 triggers offset error, and 6+12=18 > 12 triggers sum error
        yield 'offset exceeding max fails' => [6, 12, false, 2];

        // width=8 valid, offset=6 valid, but 8+6=14 > 12
        yield 'width plus offset exceeding column count fails' => [8, 6, false, 1];

        // Width 13 exceeds 12, offset 12 >= 12, and width+offset 25 > 12
        yield 'multiple violations all reported' => [13, 12, false, 3];

        yield 'width at column count passes' => [12, 0, true, 0];

        // offset=11 is the max valid offset (< 12)
        yield 'offset at max minus one passes' => [1, 11, true, 0];

        // width=6 + offset=6 = 12, which is NOT exceeded (> check, not >=)
        yield 'width plus offset at exact column count passes' => [6, 6, true, 0];
    }

    public function testOverrideValidated(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(6, 0, true),
            ['md' => new ViewportConfig(13, 0, true)],
        );
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
    }

    public function testNonGridSettingsValuePasses(): void
    {
        $validator = new GridSettingsFieldValidator('GridSettings', null, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertTrue($result->isValid());
    }
}
