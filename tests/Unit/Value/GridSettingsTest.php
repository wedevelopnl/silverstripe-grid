<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettings::class)]
final class GridSettingsTest extends TestCase
{
    // ─── Factories ─────────────────────────────────────────────

    public function testInitialCreatesFullWidthDefaults(): void
    {
        $settings = GridSettings::initial(12);

        $this->assertSame(12, $settings->default->width);
        $this->assertSame(0, $settings->default->offset);
        $this->assertTrue($settings->default->visible);
        $this->assertSame([], $settings->overrides);
    }

    public function testFromJsonWithValidNewFormat(): void
    {
        $json = json_encode([
            'default' => ['width' => 8, 'offset' => 1, 'visible' => true],
            'overrides' => [
                'sm' => ['width' => 12, 'offset' => 0, 'visible' => false],
            ],
        ]);

        $settings = GridSettings::fromJson($json, 12);

        $this->assertSame(8, $settings->default->width);
        $this->assertSame(1, $settings->default->offset);
        $this->assertTrue($settings->default->visible);
        $this->assertCount(1, $settings->overrides);
        $this->assertSame(12, $settings->overrides['sm']->width);
        $this->assertFalse($settings->overrides['sm']->visible);
    }

    public function testFromJsonWithEmptyStringReturnsInitial(): void
    {
        $settings = GridSettings::fromJson('', 12);

        $this->assertSame(12, $settings->default->width);
        $this->assertSame([], $settings->overrides);
    }

    public function testFromJsonWithEmptyObjectReturnsInitial(): void
    {
        $settings = GridSettings::fromJson('{}', 12);

        $this->assertSame(12, $settings->default->width);
        $this->assertSame([], $settings->overrides);
    }

    public function testFromJsonWithEmptyArrayReturnsInitial(): void
    {
        $settings = GridSettings::fromJson('[]', 12);

        $this->assertSame(12, $settings->default->width);
        $this->assertSame([], $settings->overrides);
    }

    public function testFromJsonWithInvalidJsonReturnsInitial(): void
    {
        $settings = GridSettings::fromJson('not json', 12);

        $this->assertSame(12, $settings->default->width);
        $this->assertSame([], $settings->overrides);
    }

    public function testFromJsonWithMissingDefaultKeyReturnsInitial(): void
    {
        $settings = GridSettings::fromJson('{"foo": "bar"}', 12);

        $this->assertSame(12, $settings->default->width);
        $this->assertSame([], $settings->overrides);
    }

    public function testFromJsonWithNoOverridesKey(): void
    {
        $json = json_encode([
            'default' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ]);

        $settings = GridSettings::fromJson($json, 12);

        $this->assertSame(6, $settings->default->width);
        $this->assertSame([], $settings->overrides);
    }

    public function testFromJsonWithMultipleOverrides(): void
    {
        $json = json_encode([
            'default' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'overrides' => [
                'sm' => ['width' => 6, 'offset' => 0, 'visible' => true],
                'lg' => ['width' => 4, 'offset' => 2, 'visible' => false],
            ],
        ]);

        $settings = GridSettings::fromJson($json, 12);

        $this->assertCount(2, $settings->overrides);
        $this->assertSame(6, $settings->overrides['sm']->width);
        $this->assertSame(4, $settings->overrides['lg']->width);
    }

    // ─── Queries ───────────────────────────────────────────────

    public function testHasOverrideReturnsTrueForExistingOverride(): void
    {
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['sm' => new ViewportConfig(6, 0, true)],
        );

        $this->assertTrue($settings->hasOverride('sm'));
    }

    public function testHasOverrideReturnsFalseForMissingOverride(): void
    {
        $settings = GridSettings::initial(12);

        $this->assertFalse($settings->hasOverride('sm'));
    }

    public function testGetOverrideReturnsConfigForExistingOverride(): void
    {
        $override = new ViewportConfig(6, 0, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['sm' => $override]);

        $result = $settings->getOverride('sm');

        $this->assertNotNull($result);
        $this->assertTrue($override->equals($result));
    }

    public function testGetOverrideReturnsNullForMissingOverride(): void
    {
        $settings = GridSettings::initial(12);

        $this->assertNull($settings->getOverride('sm'));
    }

    public function testForViewportReturnsOverrideWhenPresent(): void
    {
        $override = new ViewportConfig(6, 0, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['sm' => $override]);

        $result = $settings->forViewport('sm');

        $this->assertTrue($override->equals($result));
    }

    public function testForViewportReturnsDefaultWhenNoOverride(): void
    {
        $settings = GridSettings::initial(12);

        $result = $settings->forViewport('sm');

        $this->assertTrue($settings->default->equals($result));
    }

    // ─── Immutable updates ─────────────────────────────────────

    public function testWithDefaultReturnsNewInstanceWithUpdatedDefault(): void
    {
        $original = GridSettings::initial(12);
        $newDefault = new ViewportConfig(6, 1, true);
        $updated = $original->withDefault($newDefault);

        $this->assertNotSame($original, $updated);
        $this->assertSame(12, $original->default->width);
        $this->assertSame(6, $updated->default->width);
    }

    public function testWithDefaultPreservesOverrides(): void
    {
        $override = new ViewportConfig(4, 0, false);
        $settings = new GridSettings(ViewportConfig::default(12), ['sm' => $override]);

        $updated = $settings->withDefault(new ViewportConfig(8, 0, true));

        $this->assertTrue($updated->hasOverride('sm'));
        $this->assertTrue($override->equals($updated->overrides['sm']));
    }

    public function testWithOverrideAddsNewOverride(): void
    {
        $settings = GridSettings::initial(12);
        $override = new ViewportConfig(6, 0, false);

        $updated = $settings->withOverride('sm', $override);

        $this->assertFalse($settings->hasOverride('sm'));
        $this->assertTrue($updated->hasOverride('sm'));
        $this->assertTrue($override->equals($updated->overrides['sm']));
    }

    public function testWithOverrideReplacesExistingOverride(): void
    {
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['sm' => new ViewportConfig(6, 0, true)],
        );
        $newOverride = new ViewportConfig(8, 1, false);

        $updated = $settings->withOverride('sm', $newOverride);

        $this->assertTrue($newOverride->equals($updated->overrides['sm']));
    }

    public function testWithoutOverrideRemovesExistingOverride(): void
    {
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['sm' => new ViewportConfig(6, 0, true)],
        );

        $updated = $settings->withoutOverride('sm');

        $this->assertFalse($updated->hasOverride('sm'));
    }

    public function testWithoutOverrideIsNoOpForMissingKey(): void
    {
        $settings = GridSettings::initial(12);

        $updated = $settings->withoutOverride('sm');

        $this->assertSame([], $updated->overrides);
    }

    // ─── Persistence ───────────────────────────────────────────

    public function testToJsonProducesValidJson(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(8, 1, true),
            ['sm' => new ViewportConfig(12, 0, false)],
        );

        $json = $settings->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(8, $decoded['default']['width']);
        $this->assertSame(1, $decoded['default']['offset']);
        $this->assertTrue($decoded['default']['visible']);
        $this->assertSame(12, $decoded['overrides']['sm']['width']);
        $this->assertFalse($decoded['overrides']['sm']['visible']);
    }

    public function testToJsonWithNoOverridesProducesEmptyOverridesObject(): void
    {
        $settings = GridSettings::initial(12);
        $json = $settings->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame([], $decoded['overrides']);
    }

    public function testJsonRoundTrip(): void
    {
        $original = new GridSettings(
            new ViewportConfig(8, 1, true),
            [
                'sm' => new ViewportConfig(12, 0, false),
                'lg' => new ViewportConfig(4, 2, true),
            ],
        );

        $restored = GridSettings::fromJson($original->toJson(), 12);

        $this->assertTrue($original->default->equals($restored->default));
        $this->assertCount(2, $restored->overrides);
        $this->assertTrue($original->overrides['sm']->equals($restored->overrides['sm']));
        $this->assertTrue($original->overrides['lg']->equals($restored->overrides['lg']));
    }
}
