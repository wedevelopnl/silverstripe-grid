<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Elements;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Validation\ValidationException;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\GridElement;

#[CoversClass(Column::class)]
final class ColumnTest extends ContainerContractTestCase
{
    protected function createContainer(): ContainerInterface
    {
        $column = Column::create();
        $column->write();

        return $column;
    }

    protected function expectedIcon(): string
    {
        return 'font-icon-block-content';
    }

    protected function expectedPluralName(): string
    {
        return 'Columns';
    }

    protected function expectedClassDescription(): string
    {
        return 'Responsive grid column that holds content blocks';
    }

    protected function containerClass(): string
    {
        return Column::class;
    }

    public function testGetContainerTypeReturnsColumn(): void
    {
        $column = $this->createContainer();

        $this->assertSame(ContainerType::Column, $column->getContainerType());
    }

    public function testWriteBlockedOnPage(): void
    {
        $page = \Page::create();
        $page->Title = 'Test Page';
        $page->write();

        $column = Column::create();
        $column->ParentID = $page->ID;
        $column->ParentClass = \Page::class;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Column cannot be placed at page level.');
        $column->write();
    }

    public function testWriteBlockedInsideSection(): void
    {
        $section = Section::create();
        $section->write();

        $column = Column::create();
        $column->ParentID = $section->ID;
        $column->ParentClass = Section::class;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Column cannot be placed inside Section.');
        $column->write();
    }

    public function testWriteBlockedInsideColumn(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = $row->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column);

        $innerColumn = Column::create();
        $innerColumn->ParentID = $column->ID;
        $innerColumn->ParentClass = Column::class;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Column cannot be placed inside Column.');
        $innerColumn->write();
    }

    public function testWriteSucceedsInsideRow(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = Column::create();
        $column->ParentID = $row->ID;
        $column->ParentClass = Row::class;
        $column->write();

        $this->assertGreaterThan(0, $column->ID);
    }

    public function testDoesNotScaffoldChildren(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $this->assertFalse($column->hasChildren());
    }

    public function testGetTypeReturnsColumn(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $this->assertSame('Column', $column->getType());
    }

    public function testGetSummaryReturnsZeroElementsForEmptyColumn(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $this->assertSame('0 elements', $column->getSummary());
    }

    public function testGetGridWidthSummaryWithEmptySettings(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        // Empty sparse settings → full-width default
        $this->assertSame('12/12', $column->getGridWidthSummary());
    }

    public function testGetGridWidthSummaryWithCustomSettings(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $column->setGridSettings(new GridSettings(
            new ViewportConfig(6, 0, true),
        ));
        $column->write();

        $this->assertSame('6/12', $column->getGridWidthSummary());
    }

    public function testGetChildCountSummaryIsPubliclyCallable(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $this->assertSame('0 elements', $column->getChildCountSummary());
    }

    public function testGetChildCountSummarySingularWithOneChild(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $leaf = GridElement::create();
        $leaf->Title = 'Test Leaf';
        $leaf->ParentID = $column->ID;
        $leaf->ParentClass = Column::class;
        $leaf->write();

        $this->assertSame('1 element', $column->getChildCountSummary());
    }

    public function testSummaryFieldsIncludesContentsAndWidthColumns(): void
    {
        $fields = Column::config()->get('summary_fields');

        $this->assertArrayHasKey('getChildCountSummary', $fields);
        $this->assertSame('Contents', $fields['getChildCountSummary']);
        $this->assertArrayHasKey('getGridWidthSummary', $fields);
        $this->assertSame('Width', $fields['getGridWidthSummary']);
    }

    public function testNewColumnHasInitialSettings(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $settings = $column->getGridSettings();
        $this->assertInstanceOf(GridSettings::class, $settings);
        $this->assertSame(12, $settings->default->width);
        $this->assertSame(0, $settings->default->offset);
        $this->assertTrue($settings->default->visible);
        $this->assertSame([], $settings->overrides);
    }

    public function testGridSettingsRoundTrip(): void
    {
        $settings = new GridSettings(
            new ViewportConfig(6, 3, true),
            [
                'md' => new ViewportConfig(4, 0, false),
                'lg' => new ViewportConfig(8, 2, true),
            ],
        );

        $column = $this->createContainer();
        /** @var Column $column */
        $column->setGridSettings($settings);
        $column->write();

        // Re-fetch from DB to verify persistence
        /** @var Column $reloaded */
        $reloaded = Column::get()->byID($column->ID);
        $reloadedSettings = $reloaded->getGridSettings();

        $this->assertTrue($reloadedSettings->default->equals($settings->default));
        $this->assertCount(2, $reloadedSettings->overrides);
        $this->assertTrue($reloadedSettings->overrides['md']->equals($settings->overrides['md']));
        $this->assertTrue($reloadedSettings->overrides['lg']->equals($settings->overrides['lg']));
    }

    public function testOnBeforeWritePersistsInitialSettingsForNewRecord(): void
    {
        $column = Column::create();
        $column->write();

        // After write, the composite sub-fields should be populated
        $settings = $column->getGridSettings();
        $this->assertInstanceOf(GridSettings::class, $settings);
        $this->assertSame(12, $settings->default->width);
        $this->assertSame(0, $settings->default->offset);
        $this->assertTrue($settings->default->visible);
    }

    public function testGetColumnClassesReturnsString(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        $classes = $column->getColumnClasses();

        // Verifies the method executes fully including the extend() call
        $this->assertIsString($classes);
        $this->assertNotEmpty($classes);
    }

    public function testOnBeforeWriteDoesNotReinitializeSettingsForPersistedRecord(): void
    {
        $column = $this->createContainer();
        /** @var Column $column */

        // Set custom settings on the persisted record
        $custom = new GridSettings(
            new ViewportConfig(6, 2, false),
        );
        $column->setGridSettings($custom);
        $column->write();

        // Re-write the persisted record (triggers onBeforeWrite again)
        $column->Title = 'Updated Title';
        $column->write();

        // Settings must NOT be reset to initial — the record is already in DB
        $settings = $column->getGridSettings();
        $this->assertSame(6, $settings->default->width);
        $this->assertSame(2, $settings->default->offset);
        $this->assertFalse($settings->default->visible);
    }

    public function testNewColumnGetsInitialGridSettingsAfterWrite(): void
    {
        $column = Column::create();

        // Before write, the composite field has no data
        /** @var \WeDevelop\Grid\ORM\FieldType\DBGridSettings $fieldBefore */
        $fieldBefore = $column->dbObject('GridSettings');
        $this->assertFalse($fieldBefore->exists());

        $column->write();

        // After write, onBeforeWrite must have initialized GridSettings
        /** @var \WeDevelop\Grid\ORM\FieldType\DBGridSettings $fieldAfter */
        $fieldAfter = $column->dbObject('GridSettings');
        $this->assertTrue($fieldAfter->exists());

        $settings = $column->getGridSettings();
        $this->assertSame(12, $settings->default->width);
    }

    public function testPresetGridSettingsNotOverwrittenOnFirstWrite(): void
    {
        $custom = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['md' => new ViewportConfig(6, 0, true)],
        );

        $column = Column::create();
        $column->setGridSettings($custom);
        $column->write();

        /** @var Column $reloaded */
        $reloaded = Column::get()->byID($column->ID);
        $reloadedSettings = $reloaded->getGridSettings();

        $this->assertTrue($reloadedSettings->default->equals($custom->default));
        $this->assertCount(1, $reloadedSettings->overrides);
        $this->assertTrue($reloadedSettings->overrides['md']->equals($custom->overrides['md']));
    }
}
