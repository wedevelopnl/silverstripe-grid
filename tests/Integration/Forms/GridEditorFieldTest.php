<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Tests\Integration\Fixture\TestController;
use WeDevelop\Grid\Tests\Integration\Forms\Stub\GridSettingsRecordStub;

#[CoversClass(GridEditorField::class)]
final class GridEditorFieldTest extends SapphireTest
{
    public function testFieldNameMatchesConstructorArgument(): void
    {
        $field = GridEditorField::create('GridEditor', 42);

        $this->assertSame('GridEditor', $field->getName());
    }

    public function testHasGridEditorContainerCssClass(): void
    {
        $field = GridEditorField::create('GridEditor', 42);

        $this->assertStringContainsString('grid-editor__container', $field->extraClass());
    }

    public function testHasNoChangeTrackCssClass(): void
    {
        $field = GridEditorField::create('GridEditor', 42);

        $this->assertStringContainsString('no-change-track', $field->extraClass());
    }

    public function testSchemaDataContainsGridPageId(): void
    {
        $field = GridEditorField::create('GridEditor', 42);
        $this->attachToForm($field);
        $schemaData = $field->getSchemaDataDefaults();

        $this->assertIsInt($schemaData['grid-page-id']);
        $this->assertSame(42, $schemaData['grid-page-id']);
    }

    public function testPerformReadonlyTransformationReturnsLiteralField(): void
    {
        $field = GridEditorField::create('GridEditor', 42);

        $this->assertInstanceOf(LiteralField::class, $field->performReadonlyTransformation());
    }

    public function testConfigContainsGridFieldDetailForm(): void
    {
        $field = GridEditorField::create('GridEditor', 42);

        $this->assertNotNull(
            $field->getConfig()->getComponentByType(GridFieldDetailForm::class),
        );
    }

    public function testSchemaDataContainsGridZoneDefault(): void
    {
        $field = GridEditorField::create('GridEditor', 42);
        $this->attachToForm($field);
        $schemaData = $field->getSchemaDataDefaults();

        $this->assertSame('main', $schemaData['grid-zone']);
    }

    public function testSchemaDataContainsCustomGridZone(): void
    {
        $field = GridEditorField::create('GridEditor', 42, 'sidebar');
        $this->attachToForm($field);
        $schemaData = $field->getSchemaDataDefaults();

        $this->assertSame('sidebar', $schemaData['grid-zone']);
    }

    public function testGetPageIdReturnsConstructorValue(): void
    {
        $field = GridEditorField::create('GridEditor', 42);

        $this->assertSame(42, $field->getPageId());
    }

    public function testGetZoneReturnsDefaultValue(): void
    {
        $field = GridEditorField::create('GridEditor', 42);

        $this->assertSame('main', $field->getZone());
    }

    public function testGetZoneReturnsCustomValue(): void
    {
        $field = GridEditorField::create('GridEditor', 42, 'sidebar');

        $this->assertSame('sidebar', $field->getZone());
    }

    public function testSaveIntoDoesNotModifyRecord(): void
    {
        $field = GridEditorField::create('GridEditor', 42);
        $record = new GridSettingsRecordStub();

        $field->saveInto($record);

        $this->assertNull($record->GridEditor);
    }

    public function testFieldHolderRendersTemplate(): void
    {
        $field = GridEditorField::create('GridEditor', 42);
        $this->attachToForm($field);

        $result = $field->FieldHolder();

        $this->assertInstanceOf(DBHTMLText::class, $result);
        $this->assertNotEmpty($result->getValue());
    }

    public function testFieldHolderWithPropertiesCustomisesContext(): void
    {
        $field = GridEditorField::create('GridEditor', 42);
        $this->attachToForm($field);

        $result = $field->FieldHolder(['Foo' => 'bar']);

        $this->assertInstanceOf(DBHTMLText::class, $result);
        $this->assertNotEmpty($result->getValue());
    }

    /** Attach a field to a minimal form so GridField::Link() doesn't throw. */
    private function attachToForm(GridEditorField $field): void
    {
        $form = Form::create(TestController::create(), 'TestForm', FieldList::create($field), FieldList::create());
        $field->setForm($form);
    }
}
