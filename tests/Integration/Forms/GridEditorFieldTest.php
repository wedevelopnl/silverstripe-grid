<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Tests\Integration\Fixture\TestController;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;

#[CoversClass(GridEditorField::class)]
final class GridEditorFieldTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        TestPage::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        TestPage::class => [
            GridPageExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testFieldNameMatchesConstructorArgument(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID);

        $this->assertSame('GridEditor', $field->getName());
    }

    public function testHasGridEditorContainerCssClass(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID);

        $this->assertStringContainsString('grid-editor__container', $field->extraClass());
    }

    public function testHasNoChangeTrackCssClass(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID);

        $this->assertStringContainsString('no-change-track', $field->extraClass());
    }

    public function testSchemaDataContainsGridPageId(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID);
        $this->attachToForm($field);
        $schemaData = $field->getSchemaDataDefaults();

        $this->assertIsInt($schemaData['grid-page-id']);
        $this->assertSame((int) $page->ID, $schemaData['grid-page-id']);
    }

    public function testPerformReadonlyTransformationReturnsLiteralField(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID);

        $this->assertInstanceOf(LiteralField::class, $field->performReadonlyTransformation());
    }

    public function testConfigContainsGridFieldDetailForm(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID);

        $this->assertNotNull(
            $field->getConfig()->getComponentByType(GridFieldDetailForm::class),
        );
    }

    public function testSchemaDataContainsGridZoneDefault(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID);
        $this->attachToForm($field);
        $schemaData = $field->getSchemaDataDefaults();

        $this->assertSame('main', $schemaData['grid-zone']);
    }

    public function testSchemaDataContainsCustomGridZone(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $field = GridEditorField::create('GridEditor', (int) $page->ID, 'sidebar');
        $this->attachToForm($field);
        $schemaData = $field->getSchemaDataDefaults();

        $this->assertSame('sidebar', $schemaData['grid-zone']);
    }

    /** Attach a field to a minimal form so GridField::Link() doesn't throw. */
    private function attachToForm(GridEditorField $field): void
    {
        $form = Form::create(TestController::create(), 'TestForm', FieldList::create($field), FieldList::create());
        $field->setForm($form);
    }
}
