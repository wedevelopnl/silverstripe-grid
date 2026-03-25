<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\ORM\FieldType;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\ORM\FieldType\DBGridSettings;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(DBGridSettings::class)]
class DBGridSettingsTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function createField(): DBGridSettings
    {
        return DBGridSettings::create('GridSettings');
    }

    // ─── getValue ───────────────────────────────────────────────

    public function testGetValueReturnsNullWhenNoData(): void
    {
        $field = $this->createField();

        $this->assertNull($field->getValue());
    }


    public function testGetValueReconstructsDefaultOnly(): void
    {
        $field = $this->createField();
        $field->setField('DefaultWidth', 8);
        $field->setField('DefaultOffset', 1);
        $field->setField('DefaultVisible', true);
        $field->setField('Overrides', '');

        $settings = $field->getValue();

        $this->assertNotNull($settings);
        $this->assertSame(8, $settings->default->width);
        $this->assertSame(1, $settings->default->offset);
        $this->assertTrue($settings->default->visible);
        $this->assertSame([], $settings->overrides);
    }

    public function testGetValueReconstructsWithOverrides(): void
    {
        $field = $this->createField();
        $field->setField('DefaultWidth', 8);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);
        $field->setField('Overrides', json_encode([
            'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => false],
        ]));

        $settings = $field->getValue();

        $this->assertNotNull($settings);
        $this->assertCount(2, $settings->overrides);
        $this->assertSame(6, $settings->overrides['lg']->width);
        $this->assertSame(12, $settings->overrides['xs']->width);
        $this->assertFalse($settings->overrides['xs']->visible);
    }

    public function testGetValueHandlesEmptyJsonOverrides(): void
    {
        $field = $this->createField();
        $field->setField('DefaultWidth', 12);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);
        $field->setField('Overrides', '{}');

        $settings = $field->getValue();

        $this->assertNotNull($settings);
        $this->assertSame([], $settings->overrides);
    }

    public function testGetValueHandlesNullOverrides(): void
    {
        $field = $this->createField();
        $field->setField('DefaultWidth', 12);
        $field->setField('DefaultOffset', 0);
        $field->setField('DefaultVisible', true);
        $field->setField('Overrides', null);

        $settings = $field->getValue();

        $this->assertNotNull($settings);
        $this->assertSame([], $settings->overrides);
    }

    // ─── setValue ────────────────────────────────────────────────

    public function testSetValueFromGridSettingsVO(): void
    {
        $field = $this->createField();
        $settings = new GridSettings(
            new ViewportConfig(8, 1, true),
            ['lg' => new ViewportConfig(6, 0, false)],
        );

        $field->setValue($settings);

        $this->assertSame(8, $field->getField('DefaultWidth'));
        $this->assertSame(1, $field->getField('DefaultOffset'));
        $this->assertTrue($field->getField('DefaultVisible'));

        $overridesJson = $field->getField('Overrides');
        $this->assertIsString($overridesJson);
        $decoded = json_decode($overridesJson, true);
        $this->assertSame(6, $decoded['lg']['width']);
        $this->assertFalse($decoded['lg']['visible']);
    }

    public function testSetValueWithNoOverridesStoresNull(): void
    {
        $field = $this->createField();
        $settings = GridSettings::initial(12);

        $field->setValue($settings);

        $this->assertNull($field->getField('Overrides'));
    }

    public function testSetValueFromArray(): void
    {
        $field = $this->createField();

        $field->setValue([
            'DefaultWidth' => 6,
            'DefaultOffset' => 2,
            'DefaultVisible' => false,
            'Overrides' => '{"sm":{"width":12,"offset":0,"visible":true}}',
        ]);

        $this->assertSame(6, $field->getField('DefaultWidth'));
        $this->assertSame(2, $field->getField('DefaultOffset'));
        $this->assertFalse($field->getField('DefaultVisible'));
    }

    // ─── Round-trip ─────────────────────────────────────────────

    public function testSetValueThenGetValueRoundTrips(): void
    {
        $original = new GridSettings(
            new ViewportConfig(8, 1, true),
            [
                'sm' => new ViewportConfig(12, 0, false),
                'lg' => new ViewportConfig(4, 2, true),
            ],
        );

        $field = $this->createField();
        $field->setValue($original);

        $restored = $field->getValue();

        $this->assertNotNull($restored);
        $this->assertTrue($original->default->equals($restored->default));
        $this->assertCount(2, $restored->overrides);
        $this->assertTrue($original->overrides['sm']->equals($restored->overrides['sm']));
        $this->assertTrue($original->overrides['lg']->equals($restored->overrides['lg']));
    }

    // ─── exists ─────────────────────────────────────────────────

    public function testExistsReturnsFalseWhenEmpty(): void
    {
        $field = $this->createField();

        $this->assertFalse($field->exists());
    }

    public function testExistsReturnsTrueWhenDefaultWidthSet(): void
    {
        $field = $this->createField();
        $field->setValue(GridSettings::initial(12));

        $this->assertTrue($field->exists());
    }

    // ─── scaffoldFormField ──────────────────────────────────────

    public function testScaffoldFormFieldReturnsNull(): void
    {
        $field = $this->createField();

        $this->assertNull($field->scaffoldFormField());
    }
}
