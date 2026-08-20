<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(GridEditorField::class)]
final class GridEditorFieldTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testConstructorSetsPageIdAndZone(): void
    {
        $field = new GridEditorField('GridEditor', 42, 'sidebar');

        self::assertSame(42, $field->getPageId());
        self::assertSame('sidebar', $field->getZone());
    }

    public function testConstructorDefaultsZoneToMain(): void
    {
        $field = new GridEditorField('GridEditor', 42);

        self::assertSame('main', $field->getZone());
    }

    public function testGetSchemaDataDefaultsIncludesGridAttributes(): void
    {
        $field = new GridEditorField('GridEditor', 42);
        $this->attachToForm($field);

        $schema = $field->getSchemaDataDefaults();

        self::assertArrayHasKey('grid-page-id', $schema);
        self::assertArrayHasKey('grid-zone', $schema);
        self::assertSame(42, $schema['grid-page-id']);
        self::assertSame('main', $schema['grid-zone']);
    }

    public function testConstructorAddsNoChangeTrackClassAndReactMountAttribute(): void
    {
        $field = new GridEditorField('GridEditor', 42);
        $this->attachToForm($field);

        self::assertStringContainsString('no-change-track', $field->extraClass());
        self::assertStringNotContainsString('grid-editor__container', $field->extraClass());
        self::assertSame('grid-editor', $field->getAttribute('data-react-mount'));
    }

    public function testSaveIntoIsNoOp(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column] = GridTreeFactory::containerTree($page);

        $field = new GridEditorField('GridEditor', $page->ID);

        // saveInto is intentionally empty: it must neither throw nor mutate the
        // passed record. Assert the column is untouched rather than assertTrue(true),
        // which an implementation that wrote to the record would still pass.
        $field->saveInto($column);

        self::assertFalse($column->isChanged(), 'saveInto must not mutate the record');
    }

    public function testPerformReadonlyTransformationReturnsReadonlyClone(): void
    {
        $field = new GridEditorField('GridEditor', 42);

        $readonly = $field->performReadonlyTransformation();

        self::assertInstanceOf(GridEditorField::class, $readonly);
        self::assertNotSame($field, $readonly);
        self::assertTrue($readonly->isReadonly());
    }

    public function testReadonlyFieldIncludesVersionInSchemaData(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $field = new GridEditorField('GridEditor', $page->ID);
        $form = $this->attachToForm($field, $page);

        self::assertSame($page, $form->getRecord());

        $readonly = $field->performReadonlyTransformation();
        $this->attachToForm($readonly);

        $schema = $readonly->getSchemaDataDefaults();

        self::assertTrue($schema['grid-readonly']);
        self::assertSame((int) $page->Version, $schema['grid-version']);
    }

    public function testReadonlyFieldSchemaOmitsVersionWhenNoRecord(): void
    {
        $field = new GridEditorField('GridEditor', 42);

        $readonly = $field->performReadonlyTransformation();
        $this->attachToForm($readonly);

        $schema = $readonly->getSchemaDataDefaults();

        self::assertTrue($schema['grid-readonly']);
        self::assertArrayNotHasKey('grid-version', $schema);
    }

    public function testReadonlyFieldSchemaOmitsVersionWhenTheFormHasNoRecord(): void
    {
        // A form without a record: getForm() is non-null but getRecord() is null, so the
        // SECOND nullsafe operator is what protects the Version read. The no-form test
        // above short-circuits on the first operator and never exercises this.
        $field = new GridEditorField('GridEditor', 42);
        $form = $this->attachToForm($field);
        self::assertNull($form->getRecord());

        $readonly = $field->performReadonlyTransformation();
        $this->attachToForm($readonly);

        $schema = $readonly->getSchemaDataDefaults();

        self::assertTrue($schema['grid-readonly']);
        self::assertArrayNotHasKey('grid-version', $schema);
    }

    public function testReadonlyFieldSchemaOmitsVersionForANonPositiveVersion(): void
    {
        // Pins the `min_range => 1` option itself: dropping it would let filter_var
        // accept 0 and publish a meaningless grid-version of 0.
        $page = new Page();
        $page->Title = 'Unsaved';
        $page->Version = 0;

        $field = new GridEditorField('GridEditor', 42);
        $form = Form::create(
            Controller::create(),
            'TestForm',
            FieldList::create($field),
            FieldList::create(),
        );
        $form->setFormAction('/test');
        $form->loadDataFrom($page);
        self::assertSame(0, (int) $form->getRecord()->Version);

        $readonly = $field->performReadonlyTransformation();
        $this->attachToForm($readonly);

        $schema = $readonly->getSchemaDataDefaults();

        self::assertArrayNotHasKey('grid-version', $schema);
    }

    public function testFieldHolderAppliesSuppliedCustomisationProperties(): void
    {
        $field = new GridEditorField('GridEditor', 42);
        $this->attachToForm($field);

        $html = (string) $field->FieldHolder(['HolderID' => 'customised-holder']);

        self::assertStringContainsString('id="customised-holder"', $html);
    }

    public function testFieldHolderWithoutPropertiesUsesTheFieldsOwnHolderId(): void
    {
        // Separate field: customise() leaves the customised data attached to the instance.
        $field = new GridEditorField('GridEditor', 42);
        $this->attachToForm($field);

        $html = (string) $field->FieldHolder();

        self::assertStringContainsString('id="' . $field->HolderID() . '"', $html);
    }

    public function testReadonlyFieldIncludesVersionForVersionOneRecord(): void
    {
        // A record whose Version is exactly 1 must still surface 'grid-version'.
        // Pins the `min_range => 1` lower bound on the version filter_var:
        // bumping it to 2 would reject Version 1 and drop the key entirely.
        // A page written exactly once is at Version 1.
        $page = new Page();
        $page->Title = 'Version One Page';
        $page->write();
        self::assertSame(1, (int) $page->Version, 'A page written once must be at Version 1 for this guard test');

        $field = new GridEditorField('GridEditor', (int) $page->ID);
        $form = Form::create(
            Controller::create(),
            'TestForm',
            FieldList::create($field),
            FieldList::create(),
        );
        $form->setFormAction('/test');
        $form->loadDataFrom($page);

        $readonly = $field->performReadonlyTransformation();
        $this->attachToForm($readonly);

        $schema = $readonly->getSchemaDataDefaults();

        self::assertArrayHasKey('grid-version', $schema);
        self::assertSame(1, $schema['grid-version']);
    }

    public function testFieldHolderReturnsDBHTMLText(): void
    {
        $field = new GridEditorField('GridEditor', 42);
        $this->attachToForm($field);

        self::assertInstanceOf(DBHTMLText::class, $field->FieldHolder());
    }

    public function testFieldDeclaresCustomSchemaTypeAndComponent(): void
    {
        $field = GridEditorField::create('GridEditor', 42, 'main');
        $this->attachToForm($field);

        $schema = $field->getSchemaData();

        self::assertSame('Custom', $schema['schemaType']);
        self::assertSame('GridEditorField', $schema['component']);
    }

    public function testSchemaDataSubArrayContainsPageIdAndZone(): void
    {
        $field = GridEditorField::create('GridEditor', 42, 'sidebar');
        $this->attachToForm($field);

        $schema = $field->getSchemaData();

        self::assertSame(42, $schema['data']['pageId']);
        self::assertSame('sidebar', $schema['data']['zone']);
    }

    public function testReadonlyCloneExposesVersionInSchemaDataSubArray(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $form = Form::create(
            Controller::create(),
            'TestForm',
            FieldList::create(),
            FieldList::create(),
        );
        $form->setFormAction('/test');
        $form->loadDataFrom($page);

        $field = GridEditorField::create('GridEditor', (int) $page->ID, 'main');
        $field->setForm($form);

        $readonly = $field->performReadonlyTransformation();
        $schema = $readonly->getSchemaData();

        self::assertSame((int) $page->Version, $schema['data']['version']);
        self::assertTrue($schema['data']['readonly']);
    }

    /**
     * Attach a field to a minimal Form so Link() resolves, optionally loading
     * a record so the form has one to expose via getRecord().
     */
    private function attachToForm(GridEditorField $field, ?DataObject $record = null): Form
    {
        $form = Form::create(
            Controller::create(),
            'TestForm',
            FieldList::create($field),
            FieldList::create(),
        );
        $form->setFormAction('/test');

        if ($record !== null) {
            $form->loadDataFrom($record);
        }

        return $form;
    }
}
