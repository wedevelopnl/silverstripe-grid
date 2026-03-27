<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Validation\GridSettingsFieldValidator;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsFieldValidator::class)]
final class GridSettingsFieldValidatorTest extends SapphireTest
{
    private const COLUMN_COUNT = 12;

    public function testValidSettingsPass(): void
    {
        $settings = new GridSettings(new ViewportConfig(6, 0, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertTrue($result->isValid());
    }

    public function testWidthExceedingColumnCountFails(): void
    {
        // width=13 > 12 triggers width error, and 13+0=13 > 12 triggers sum error
        $settings = new GridSettings(new ViewportConfig(13, 0, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->getMessages());
    }

    public function testOffsetExceedingMaxFails(): void
    {
        // offset=12 >= 12 triggers offset error, and 6+12=18 > 12 triggers sum error
        $settings = new GridSettings(new ViewportConfig(6, 12, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->getMessages());
    }

    public function testWidthPlusOffsetExceedingColumnCountFails(): void
    {
        // width=8 valid, offset=6 valid, but 8+6=14 > 12
        $settings = new GridSettings(new ViewportConfig(8, 6, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->getMessages());
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

    public function testMultipleViolationsReported(): void
    {
        // Width 13 exceeds 12, offset 12 >= 12, and width+offset 25 > 12
        $settings = new GridSettings(new ViewportConfig(13, 12, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
        self::assertCount(3, $result->getMessages());
    }

    // ── Boundary tests ─────────────────────────────────────────

    public function testWidthAtColumnCountPasses(): void
    {
        $settings = new GridSettings(new ViewportConfig(12, 0, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertTrue($result->isValid());
    }

    public function testOffsetAtMaxMinusOnePasses(): void
    {
        // offset=11 is the max valid offset (< 12)
        $settings = new GridSettings(new ViewportConfig(1, 11, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertTrue($result->isValid());
    }

    public function testWidthPlusOffsetAtExactColumnCountPasses(): void
    {
        // width=6 + offset=6 = 12, which is NOT exceeded (> check, not >=)
        $settings = new GridSettings(new ViewportConfig(6, 6, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertTrue($result->isValid());
    }
}
