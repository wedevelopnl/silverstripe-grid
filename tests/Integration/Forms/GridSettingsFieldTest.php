<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Tests\Integration\Forms\Stub\GridSettingsRecordStub;
use WeDevelop\Grid\Value\Viewport;

/**
 * Integration tests for GridSettingsField's own logic: decodeSparse, normalizeFormData, buildReadonlySummary.
 *
 * Uses a mocked GridAdapterInterface with 3 viewports (xs, sm=default, md; 12 columns).
 * Tests exercise the field's public API (setValue → saveInto, performReadonlyTransformation)
 * rather than the compactor delegation which is covered by GridSettingsCompactorTest.
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

    // --- setValue(string) → saveInto round-trips (exercises decodeSparse) ---

    public function testSetValueEmptyStringProducesEmptySparse(): void
    {
        $field = $this->createField();
        $field->setValue('');

        $this->assertSame('{}', $this->saveAndCapture($field));
    }

    public function testSetValueEmptyObjectProducesEmptySparse(): void
    {
        $field = $this->createField();
        $field->setValue('{}');

        $this->assertSame('{}', $this->saveAndCapture($field));
    }

    public function testSetValueEmptyArrayProducesEmptySparse(): void
    {
        $field = $this->createField();
        $field->setValue('[]');

        $this->assertSame('{}', $this->saveAndCapture($field));
    }

    public function testSetValueInvalidJsonProducesEmptySparse(): void
    {
        $field = $this->createField();
        $field->setValue('not json');

        $this->assertSame('{}', $this->saveAndCapture($field));
    }

    public function testSetValueNullJsonProducesEmptySparse(): void
    {
        $field = $this->createField();
        $field->setValue('null');

        $this->assertSame('{}', $this->saveAndCapture($field));
    }

    public function testSetValueValidJsonRoundTrips(): void
    {
        $field = $this->createField();
        $input = '{"sm":{"width":6,"offset":1,"visible":true}}';
        $field->setValue($input);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);

        // Re-expand to verify effective values match: sm should be 6/1/true
        $reField = $this->createField();
        $reField->setValue($saved);
        $reRecord = new GridSettingsRecordStub();
        $reField->saveInto($reRecord);

        // Round-trip through expand→compact→expand must preserve effective values
        $this->assertSame($saved, (string) $reRecord->GridSettings);
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
        // xs is missing from form data → normalizeFormData copies default viewport values (6,1,true)
        // xs effective (6,1,true) differs from implicit cascade seed (12,0,true) → stored in sparse
        $this->assertArrayHasKey('xs', $decoded);
        $this->assertSame(6, $decoded['xs']['width']);
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
        $this->assertArrayHasKey('md', $decoded);
        $this->assertSame(4, $decoded['md']['width']);
    }

    public function testNormalizeNoOverrideUsesDefault(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
            'md' => ['width' => '4', 'offset' => '0', 'visible' => '1'],
            // no 'override' key for md → uses default viewport values
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        // md has no override → inherits sm values → no md entry in sparse
        // (unless xs cascade forces it)
        $this->assertIsArray($decoded);
        if (isset($decoded['md'])) {
            // If md appears, it should match sm values (the default viewport)
            $this->assertSame(6, $decoded['md']['width']);
        }
    }

    public function testNormalizeMissingViewportUsesDefault(): void
    {
        $field = $this->createField();
        // Only sm submitted — xs and md missing from form data
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '1', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        // Missing viewports use default viewport values (6,1,true)
        // xs gets (6,1,true) which differs from implicit (12,0,true) → stored
        $this->assertArrayHasKey('xs', $decoded);
    }

    public function testNormalizeMissingWidthDefaultsToColumnCount(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['offset' => '0', 'visible' => '1'],
            // width key missing → defaults to column count (12)
        ]);

        $saved = $this->saveAndCapture($field);

        // All defaults (12,0,true) → empty sparse
        $this->assertSame('{}', $saved);
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
        // visible=false differs from implicit defaults → sparse entry for xs with visible=false
        $this->assertNotSame('{}', $saved);
    }

    public function testNormalizeNonArrayViewportEntryTreatedAsMissing(): void
    {
        $field = $this->createField();
        $field->setValue([
            'xs' => 'string',
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        // xs is non-array → treated as missing → gets default viewport values (6,0,true)
        // xs effective (6,0,true) differs from implicit (12,0,true) → stored
        $this->assertArrayHasKey('xs', $decoded);
        $this->assertSame(6, $decoded['xs']['width']);
    }

    // --- performReadonlyTransformation (exercises buildReadonlySummary) ---

    public function testReadonlySummaryNoValueShowsFullWidth(): void
    {
        $field = $this->createField();
        // No setValue call → viewportData is empty → fallback message

        $readonly = $field->performReadonlyTransformation();

        $this->assertSame('Default (full width)', $readonly->getValue());
    }

    public function testReadonlySummaryAllDefaultsShowsDefaultViewport(): void
    {
        $field = $this->createField();
        $field->setValue('{}');

        $readonly = $field->performReadonlyTransformation();

        // Even with all defaults, sm (default viewport) is shown
        $this->assertStringContainsString('sm: 12/12+0', $readonly->getValue());
    }

    public function testReadonlySummaryShowsDefaultViewport(): void
    {
        $field = $this->createField();
        $field->setValue('{"sm":{"width":6,"offset":1,"visible":true}}');

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        $this->assertStringContainsString('sm: 6/12', $value);
    }

    public function testReadonlySummaryShowsHiddenOverride(): void
    {
        $field = $this->createField();
        $field->setValue('{"md":{"width":12,"offset":0,"visible":false}}');

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        $this->assertStringContainsString('(hidden)', $value);
    }

    public function testReadonlySummarySkipsNonOverriddenNonDefault(): void
    {
        $field = $this->createField();
        // xs has cascade value matching sm → not an override → should be skipped
        // md has explicit override → should appear
        $field->setValue('{"xs":{"width":6,"offset":0,"visible":true},"sm":{"width":6,"offset":0,"visible":true},"md":{"width":4,"offset":0,"visible":true}}');

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        // sm (default) should always appear
        $this->assertStringContainsString('sm:', $value);
        // md has override → should appear
        $this->assertStringContainsString('md:', $value);
        // xs matches sm → not an override → should be skipped
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
        $field->setValue('{"sm":{"width":6,"offset":0,"visible":true}}');

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
        $field->setValue('{"sm":{"width":6,"offset":2,"visible":true}}');

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

        // Re-expand to verify effective sm offset is 0
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
        $this->assertArrayHasKey('md', $decoded);
        $this->assertSame(4, $decoded['md']['width']);

        // Verify md offset is 0 via re-expansion
        $verifyField = $this->createField();
        $verifyField->setValue($saved);
        $mdEntry = $verifyField->getViewportData()->find('Key', 'md');

        $this->assertNotNull($mdEntry);
        $this->assertSame(0, $mdEntry->Offset);
    }
}
