<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Core\Config\ConfigLoader;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\InjectorLoader;
use SilverStripe\Core\Validation\ValidationResult;
use WeDevelop\Grid\Validation\GridSettingsFieldValidator;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsFieldValidator::class)]
final class GridSettingsFieldValidatorTest extends TestCase
{
    private const int COLUMNS = 12;

    protected function setUp(): void
    {
        parent::setUp();

        ConfigLoader::inst()->pushManifest(new MemoryConfigCollection());
        InjectorLoader::inst()->pushManifest(new Injector());
    }

    protected function tearDown(): void
    {
        InjectorLoader::inst()->popManifest();
        ConfigLoader::inst()->popManifest();

        parent::tearDown();
    }

    // ─── Happy path ─────────────────────────────────────────────

    public function testValidDefaultOnly(): void
    {
        $settings = new GridSettings(new ViewportConfig(6, 0, true));

        $result = $this->validate($settings);

        $this->assertTrue($result->isValid());
    }

    public function testValidDefaultWithOverrides(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(6, 0, true),
            ['md' => new ViewportConfig(4, 2, true)],
        );

        $result = $this->validate($settings);

        $this->assertTrue($result->isValid());
    }

    public function testWidthAtColumnCountBoundary(): void
    {
        $settings = new GridSettings(new ViewportConfig(self::COLUMNS, 0, true));

        $result = $this->validate($settings);

        $this->assertTrue($result->isValid());
    }

    public function testOffsetAtMaxBoundary(): void
    {
        $settings = new GridSettings(new ViewportConfig(1, self::COLUMNS - 1, true));

        $result = $this->validate($settings);

        $this->assertTrue($result->isValid());
    }

    // ─── Default validation failures ────────────────────────────

    public function testDefaultWidthExceedsColumns(): void
    {
        $settings = new GridSettings(new ViewportConfig(13, 0, true));

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $this->assertErrorContains($result, 'Width 13');
        $this->assertErrorContains($result, '"default"');
    }

    public function testDefaultOffsetEqualsColumnCount(): void
    {
        $settings = new GridSettings(new ViewportConfig(1, self::COLUMNS, true));

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $this->assertErrorContains($result, 'Offset 12');
        $this->assertErrorContains($result, '"default"');
    }

    public function testDefaultWidthPlusOffsetExceedsColumns(): void
    {
        $settings = new GridSettings(new ViewportConfig(8, 6, true));

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $this->assertErrorContains($result, 'Width 8 plus offset 6');
    }

    // ─── Override validation failures ───────────────────────────

    public function testOverrideWidthExceedsColumns(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(6, 0, true),
            ['lg' => new ViewportConfig(15, 0, true)],
        );

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $this->assertErrorContains($result, 'Width 15');
        $this->assertErrorContains($result, '"lg"');
    }

    public function testOverrideOffsetExceedsColumns(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(6, 0, true),
            ['sm' => new ViewportConfig(1, self::COLUMNS, true)],
        );

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $this->assertErrorContains($result, 'Offset 12');
        $this->assertErrorContains($result, '"sm"');
    }

    public function testOverrideWidthPlusOffsetExceedsColumns(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(6, 0, true),
            ['md' => new ViewportConfig(8, 6, true)],
        );

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $this->assertErrorContains($result, 'Width 8 plus offset 6');
        $this->assertErrorContains($result, '"md"');
    }

    // ─── Multiple errors ────────────────────────────────────────

    public function testMultipleErrorsAccumulated(): void
    {
        $settings = new GridSettings(new ViewportConfig(13, self::COLUMNS, true));

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $messages = $result->getMessages();
        $this->assertGreaterThanOrEqual(2, count($messages));
    }

    public function testErrorsAcrossDefaultAndOverride(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(13, 0, true),
            ['lg' => new ViewportConfig(15, 0, true)],
        );

        $result = $this->validate($settings);

        $this->assertFalse($result->isValid());
        $this->assertErrorContains($result, '"default"');
        $this->assertErrorContains($result, '"lg"');
    }

    // ─── Edge cases ─────────────────────────────────────────────

    public function testNonGridSettingsValuePassesValidation(): void
    {
        $validator = new GridSettingsFieldValidator('GridSettings', 'not-a-grid-settings', self::COLUMNS);

        $result = $validator->validate();

        $this->assertTrue($result->isValid());
    }

    public function testNullValuePassesValidation(): void
    {
        $validator = new GridSettingsFieldValidator('GridSettings', null, self::COLUMNS);

        $result = $validator->validate();

        $this->assertTrue($result->isValid());
    }

    public function testEmptyOverridesPassesValidation(): void
    {
        $settings = new GridSettings(new ViewportConfig(6, 0, true), []);

        $result = $this->validate($settings);

        $this->assertTrue($result->isValid());
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function validate(GridSettings $settings): ValidationResult
    {
        $validator = new GridSettingsFieldValidator('GridSettings', $settings, self::COLUMNS);

        return $validator->validate();
    }

    private function assertErrorContains(ValidationResult $result, string $needle): void
    {
        $messages = $result->getMessages();
        foreach ($messages as $message) {
            if (str_contains($message['message'], $needle)) {
                return;
            }
        }

        $allMessages = implode("\n", array_column($messages, 'message'));
        $this->fail(sprintf("Expected error containing \"%s\" in:\n%s", $needle, $allMessages));
    }
}
