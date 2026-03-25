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

    /**
     * Save into the stub record and return the captured GridSettings VO.
     */
    private function saveAndCapture(GridSettingsField $field): GridSettings
    {
        $record = new GridSettingsRecordStub();
        $field->saveInto($record);

        $value = $record->GridSettings;
        $this->assertInstanceOf(GridSettings::class, $value);

        return $value;
    }

    // --- setValue(GridSettings VO) → saveInto round-trips ---

    public function testInitialSettingsProducesFullWidthDefaults(): void
    {
        $field = $this->createField();
        // No setValue call → initial settings (12/0/visible)

        $saved = $this->saveAndCapture($field);

        $this->assertSame(12, $saved->default->width);
        $this->assertSame(0, $saved->default->offset);
        $this->assertTrue($saved->default->visible);
        $this->assertSame([], $saved->overrides);
    }

    public function testSetValueGridSettingsVORoundTrips(): void
    {
        $field = $this->createField();
        $input = new GridSettings(
            new ViewportConfig(6, 1, true),
        );
        $field->setValue($input);

        $saved = $this->saveAndCapture($field);

        $this->assertSame(6, $saved->default->width);
        $this->assertSame(1, $saved->default->offset);
        $this->assertTrue($saved->default->visible);
        $this->assertSame([], $saved->overrides);
    }

    public function testSetValueGridSettingsVOWithOverridesRoundTrips(): void
    {
        $field = $this->createField();
        $input = new GridSettings(
            new ViewportConfig(6, 0, true),
            ['md' => new ViewportConfig(4, 1, false)],
        );
        $field->setValue($input);

        $saved = $this->saveAndCapture($field);

        $this->assertSame(6, $saved->default->width);
        $this->assertArrayHasKey('md', $saved->overrides);
        $this->assertSame(4, $saved->overrides['md']->width);
        $this->assertSame(1, $saved->overrides['md']->offset);
        $this->assertFalse($saved->overrides['md']->visible);
    }

    // --- setValue(array) → saveInto round-trips (exercises normalizeFormData) ---

    public function testNormalizeDefaultViewportOnly(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '1', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);

        // Default viewport (sm) values stored in 'default'
        $this->assertSame(6, $saved->default->width);
        $this->assertSame(1, $saved->default->offset);
        $this->assertTrue($saved->default->visible);
        // No overrides when only default viewport submitted
        $this->assertSame([], $saved->overrides);
    }

    public function testNormalizeWithOverride(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
            'md' => ['width' => '4', 'offset' => '0', 'visible' => '1', 'override' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);

        $this->assertSame(6, $saved->default->width);
        $this->assertArrayHasKey('md', $saved->overrides);
        $this->assertSame(4, $saved->overrides['md']->width);
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

        // md has no override flag → excluded from overrides
        $this->assertArrayNotHasKey('md', $saved->overrides);
    }

    public function testNormalizeMissingViewportNotStored(): void
    {
        $field = $this->createField();
        // Only sm submitted — xs and md missing from form data
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '1', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);

        // Default viewport values stored
        $this->assertSame(6, $saved->default->width);
        // Missing non-default viewports without override flag are not stored
        $this->assertSame([], $saved->overrides);
    }

    public function testNormalizeMissingWidthDefaultsToColumnCount(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['offset' => '0', 'visible' => '1'],
            // width key missing → defaults to column count (12)
        ]);

        $saved = $this->saveAndCapture($field);

        $this->assertSame(12, $saved->default->width);
    }

    public function testNormalizeVisibleAbsenceMeansFalse(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '12', 'offset' => '0'],
            // no 'visible' key → visible=false
        ]);

        $saved = $this->saveAndCapture($field);

        $this->assertFalse($saved->default->visible);
    }

    public function testNormalizeNonArrayViewportEntryIgnored(): void
    {
        $field = $this->createField();
        $field->setValue([
            'xs' => 'string',
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
        ]);

        $saved = $this->saveAndCapture($field);

        // Default viewport (sm) stored correctly
        $this->assertSame(6, $saved->default->width);
        // xs is non-array and not the default viewport → ignored (no override stored)
        $this->assertArrayNotHasKey('xs', $saved->overrides);
    }

    // --- performReadonlyTransformation (exercises buildReadonlySummary) ---

    public function testReadonlySummaryInitialSettingsShowsDefaultViewport(): void
    {
        $field = $this->createField();
        // No setValue call → initial settings (12/0/visible)

        $readonly = $field->performReadonlyTransformation();

        $this->assertStringContainsString('sm: 12/12+0', $readonly->getValue());
    }

    public function testReadonlySummaryShowsDefaultViewportValues(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(
            new ViewportConfig(6, 1, true),
        ));

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        $this->assertStringContainsString('sm: 6/12', $value);
    }

    public function testReadonlySummaryShowsHiddenOverride(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(
            new ViewportConfig(12, 0, true),
            ['md' => new ViewportConfig(12, 0, false)],
        ));

        $readonly = $field->performReadonlyTransformation();
        $value = $readonly->getValue();

        $this->assertStringContainsString('(hidden)', $value);
    }

    public function testReadonlySummaryShowsOverrides(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(
            new ViewportConfig(6, 0, true),
            ['md' => new ViewportConfig(4, 0, true)],
        ));

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
        $field->setValue(new GridSettings(
            new ViewportConfig(6, 0, true),
        ));

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
        $field->setValue(new GridSettings(
            new ViewportConfig(6, 2, true),
        ));

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

        $this->assertSame(0, $saved->default->offset);

        // Verify via re-expansion
        $verifyField = $this->createField();
        $verifyField->setValue(new GridSettings(
            new ViewportConfig($saved->default->width, $saved->default->offset, $saved->default->visible),
            $saved->overrides,
        ));
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

        $this->assertArrayHasKey('md', $saved->overrides);
        $this->assertSame(4, $saved->overrides['md']->width);

        // Verify md offset is 0 via re-expansion
        $verifyField = $this->createField();
        $verifyField->setValue(new GridSettings(
            $saved->default,
            $saved->overrides,
        ));
        $mdEntry = $verifyField->getViewportData()->find('Key', 'md');

        $this->assertNotNull($mdEntry);
        $this->assertSame(0, $mdEntry->Offset);
    }
}
