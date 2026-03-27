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
        $settings = new GridSettings(new ViewportConfig(13, 0, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
    }

    public function testOffsetExceedingMaxFails(): void
    {
        $settings = new GridSettings(new ViewportConfig(6, 12, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
    }

    public function testWidthPlusOffsetExceedingColumnCountFails(): void
    {
        $settings = new GridSettings(new ViewportConfig(8, 6, true), []);
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMN_COUNT);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
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
        self::assertGreaterThan(1, count($result->getMessages()));
    }
}
