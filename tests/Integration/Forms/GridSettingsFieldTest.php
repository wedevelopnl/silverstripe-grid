<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Tests\Integration\Forms\Stub\GridSettingsRecordStub;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;
use WeDevelop\Grid\Value\Viewport;

/**
 * Integration tests for GridSettingsField with GridSettings VO.
 *
 * Uses a mocked GridAdapterInterface with 3 viewports (xs, sm=default, md; 12 columns).
 * Tests exercise the field's public API (setValue → saveInto, performReadonlyTransformation).
 */
#[CoversClass(GridSettingsField::class)]
final class GridSettingsFieldTest extends SapphireTest
{
    private GridAdapterInterface&MockObject $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = $this->createMock(GridAdapterInterface::class);
        $this->adapter->method('getViewports')->willReturn([
            new Viewport('xs', 'Extra Small'),
            new Viewport('sm', 'Small'),
            new Viewport('md', 'Medium'),
        ]);
        $this->adapter->method('getColumnCount')->willReturn(12);
        $this->adapter->method('getDefaultViewport')->willReturn(new Viewport('sm', 'Small'));
    }

    private function createField(): GridSettingsField
    {
        return new GridSettingsField('GridSettings', $this->adapter);
    }

    private function saveAndCapture(GridSettingsField $field): string
    {
        $record = new GridSettingsRecordStub();
        $field->saveInto($record);

        return (string) $record->GridSettings;
    }

    // --- setValue(string) → saveInto round-trips ---

    public function testSetValueEmptyStringProducesInitialSettings(): void
    {
        $field = $this->createField();
        $field->setValue('');

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('default', $decoded);
        $this->assertSame(12, $decoded['default']['width']);
        $this->assertSame(0, $decoded['default']['offset']);
        $this->assertTrue($decoded['default']['visible']);
        $this->assertSame([], $decoded['overrides']);
    }

    public function testSetValueEmptyObjectProducesInitialSettings(): void
    {
        $field = $this->createField();
        $field->setValue('{}');

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(12, $decoded['default']['width']);
    }

    public function testSetValueEmptyArrayProducesInitialSettings(): void
    {
        $field = $this->createField();
        $field->setValue('[]');

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(12, $decoded['default']['width']);
    }

    public function testSetValueInvalidJsonProducesInitialSettings(): void
    {
        $field = $this->createField();
        $field->setValue('not json');

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(12, $decoded['default']['width']);
    }

    public function testSetValueNullJsonProducesInitialSettings(): void
    {
        $field = $this->createField();
        $field->setValue('null');

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(12, $decoded['default']['width']);
    }

    public function testSetValueValidJsonRoundTrips(): void
    {
        $field = $this->createField();
        $input = '{"default":{"width":6,"offset":1,"visible":true},"overrides":{}}';
        $field->setValue($input);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(6, $decoded['default']['width']);
        $this->assertSame(1, $decoded['default']['offset']);
        $this->assertTrue($decoded['default']['visible']);

        // Round-trip: re-set from saved output must produce identical JSON
        $reField = $this->createField();
        $reField->setValue($saved);
        $reRecord = new GridSettingsRecordStub();
        $reField->saveInto($reRecord);

        $this->assertSame($saved, (string) $reRecord->GridSettings);
    }

    public function testSetValueValidJsonWithOverridesRoundTrips(): void
    {
        $field = $this->createField();
        $input = '{"default":{"width":6,"offset":0,"visible":true},"overrides":{"md":{"width":4,"offset":1,"visible":false}}}';
        $field->setValue($input);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(6, $decoded['default']['width']);
        $this->assertArrayHasKey('md', $decoded['overrides']);
        $this->assertSame(4, $decoded['overrides']['md']['width']);
        $this->assertSame(1, $decoded['overrides']['md']['offset']);
        $this->assertFalse($decoded['overrides']['md']['visible']);
    }

    // --- setValue(array) → saveInto round-trips (exercises normalizeFormData) ---

    public function testNormalizeDefaultViewportOnly(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '1', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        // Default viewport (sm) values stored in 'default'
        $this->assertSame(6, $decoded['default']['width']);
        $this->assertSame(1, $decoded['default']['offset']);
        $this->assertTrue($decoded['default']['visible']);
        // No overrides when only default viewport submitted
        $this->assertSame([], $decoded['overrides']);
    }

    public function testNormalizeWithOverride(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
            'md' => ['width' => '4', 'offset' => '0', 'visible' => '1', 'override' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(6, $decoded['default']['width']);
        $this->assertArrayHasKey('md', $decoded['overrides']);
        $this->assertSame(4, $decoded['overrides']['md']['width']);
    }

    public function testNormalizeNoOverrideFlagExcludesViewport(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
            'md' => ['width' => '4', 'offset' => '0', 'visible' => '1'],
            // no 'override' key for md → not stored as override
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        // md has no override flag → excluded from overrides
        $this->assertArrayNotHasKey('md', $decoded['overrides'] ?? []);
    }

    public function testNormalizeMissingViewportNotStored(): void
    {
        $field = $this->createField();
        // Only sm submitted — xs and md missing from form data
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '1', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        // Default viewport values stored
        $this->assertSame(6, $decoded['default']['width']);
        // Missing non-default viewports without override flag are not stored
        $this->assertSame([], $decoded['overrides']);
    }

    public function testNormalizeMissingWidthDefaultsToColumnCount(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['offset' => '0', 'visible' => '1'],
            // width key missing → defaults to column count (12)
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(12, $decoded['default']['width']);
    }

    public function testNormalizeVisibleAbsenceMeansFalse(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '12', 'offset' => '0'],
            // no 'visible' key → visible=false
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['default']['visible']);
    }

    public function testNormalizeNonArrayViewportEntryIgnored(): void
    {
        $field = $this->createField();
        $field->setValue([
            'xs' => 'string',
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        // Default viewport (sm) stored correctly
        $this->assertSame(6, $decoded['default']['width']);
        // xs is non-array and not the default viewport → ignored (no override stored)
        $this->assertArrayNotHasKey('xs', $decoded['overrides'] ?? []);
    }

    // --- performReadonlyTransformation (exercises buildReadonlySummary) ---

    public function testReadonlySummaryInitialSettingsShowsDefaultViewport(): void
    {
        $field = $this->createField();
        // No setValue call → initial settings (12/0/visible)

        $readonly = $field->performReadonlyTransformation();

        $this->assertStringContainsString('sm: 12/12+0', $readonly->getValue());
    }

    public function testReadonlySummaryAllDefaultsShowsDefaultViewport(): void
    {
        $field = $this->createField();
        $field->setValue('{}');

        $readonly = $field->performReadonlyTransformation();

        // Initial settings, sm (default viewport) is shown
        $this->assertStringContainsString('sm: 12/12+0', $readonly->getValue());
    }

    public function testReadonlySummaryShowsDefaultViewportValues(): void
    {
        $field = $this->createField();
        $field->setValue('{"default":{"width":6,"offset":1,"visible":true},"overrides":{}}');

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        $this->assertStringContainsString('sm: 6/12', $value);
    }

    public function testReadonlySummaryShowsHiddenOverride(): void
    {
        $field = $this->createField();
        $field->setValue('{"default":{"width":12,"offset":0,"visible":true},"overrides":{"md":{"width":12,"offset":0,"visible":false}}}');

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        $this->assertStringContainsString('(hidden)', $value);
    }

    public function testReadonlySummaryShowsOverrides(): void
    {
        $field = $this->createField();
        $field->setValue('{"default":{"width":6,"offset":0,"visible":true},"overrides":{"md":{"width":4,"offset":0,"visible":true}}}');

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        // sm (default) should always appear
        $this->assertStringContainsString('sm:', $value);
        // md has override → should appear
        $this->assertStringContainsString('md:', $value);
        // xs is not an override → should be absent
        $this->assertStringNotContainsString('xs:', $value);
    }

    // --- getViewportData tests ---

    public function testGetViewportDataReturnsAllViewports(): void
    {
        $field = $this->createField();

        $viewportData = $field->getViewportData();

        $this->assertCount(3, $viewportData);

        $keys = array_map(
            static fn ($item) => $item->Key,
            $viewportData->toArray(),
        );
        $this->assertSame(['xs', 'sm', 'md'], $keys);
    }

    public function testGetViewportDataMarksDefaultViewport(): void
    {
        $field = $this->createField();

        $viewportData = $field->getViewportData();

        foreach ($viewportData as $entry) {
            if ($entry->Key === 'sm') {
                $this->assertTrue($entry->IsDefault);
            } else {
                $this->assertFalse($entry->IsDefault);
            }
        }
    }

    public function testGetViewportDataContainsWidthOptions(): void
    {
        $field = $this->createField();

        $viewportData = $field->getViewportData();
        $smEntry = $viewportData->find('Key', 'sm');

        $this->assertNotNull($smEntry);
        $this->assertCount(12, $smEntry->WidthOptions);

        $firstOption = $smEntry->WidthOptions->first();
        $this->assertSame(1, $firstOption->Value);
        $this->assertSame('1/12', $firstOption->Label);

        $lastOption = $smEntry->WidthOptions->last();
        $this->assertSame(12, $lastOption->Value);
        $this->assertSame('12/12', $lastOption->Label);
    }

    public function testGetViewportDataContainsOffsetOptions(): void
    {
        $field = $this->createField();

        $viewportData = $field->getViewportData();
        $smEntry = $viewportData->find('Key', 'sm');

        $this->assertNotNull($smEntry);
        $this->assertCount(12, $smEntry->OffsetOptions);

        $firstOption = $smEntry->OffsetOptions->first();
        $this->assertSame(0, $firstOption->Value);

        $lastOption = $smEntry->OffsetOptions->last();
        $this->assertSame(11, $lastOption->Value);
    }

    public function testGetViewportDataSelectedWidthMatchesSetValue(): void
    {
        $field = $this->createField();
        $field->setValue('{"default":{"width":6,"offset":0,"visible":true},"overrides":{}}');

        $viewportData = $field->getViewportData();
        $smEntry = $viewportData->find('Key', 'sm');

        $this->assertNotNull($smEntry);

        foreach ($smEntry->WidthOptions as $option) {
            if ($option->Value === 6) {
                $this->assertTrue($option->Selected);
            } else {
                $this->assertFalse($option->Selected);
            }
        }
    }

    public function testGetViewportDataReflectsSetValue(): void
    {
        $field = $this->createField();
        $field->setValue('{"default":{"width":6,"offset":2,"visible":true},"overrides":{}}');

        $viewportData = $field->getViewportData();
        $smEntry = $viewportData->find('Key', 'sm');

        $this->assertNotNull($smEntry);
        $this->assertSame(6, $smEntry->Width);
        $this->assertSame(2, $smEntry->Offset);
        $this->assertTrue($smEntry->Visible);
    }

    // --- normalizeFormData missing-offset branches ---

    public function testNormalizeMissingOffsetDefaultsToZero(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'visible' => '1'],
            // no 'offset' key → defaults to 0
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(0, $decoded['default']['offset']);

        // Verify via re-expansion
        $verifyField = $this->createField();
        $verifyField->setValue($saved);
        $smEntry = $verifyField->getViewportData()->find('Key', 'sm');

        $this->assertNotNull($smEntry);
        $this->assertSame(0, $smEntry->Offset);
    }

    public function testNormalizeOverrideMissingOffsetDefaultsToZero(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
            'md' => ['width' => '4', 'visible' => '1', 'override' => '1'],
            // md has override but no 'offset' key → defaults to 0
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('md', $decoded['overrides']);
        $this->assertSame(4, $decoded['overrides']['md']['width']);

        // Verify md offset is 0 via re-expansion
        $verifyField = $this->createField();
        $verifyField->setValue($saved);
        $mdEntry = $verifyField->getViewportData()->find('Key', 'md');

        $this->assertNotNull($mdEntry);
        $this->assertSame(0, $mdEntry->Offset);
    }
}
