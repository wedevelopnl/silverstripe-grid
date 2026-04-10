<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(GridEditorField::class)]
final class GridEditorFieldTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
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

    public function testConstructorAddsExtraClass(): void
    {
        $field = new GridEditorField('GridEditor', 42);

        self::assertStringContainsString('grid-editor__container', $field->extraClass());
        self::assertStringContainsString('no-change-track', $field->extraClass());
    }

    public function testSaveIntoIsNoOp(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $field = new GridEditorField('GridEditor', $page->ID);

        // saveInto is intentionally empty, so calling it should not throw
        $field->saveInto($column);

        self::assertTrue(true, 'saveInto completed without exception');
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
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $field = new GridEditorField('GridEditor', $page->ID);
        $form = Form::create(
            Controller::create(),
            'TestForm',
            FieldList::create($field),
            FieldList::create(),
        );
        $form->setFormAction('/test');
        $form->loadDataFrom($page);

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

    public function testFieldHolderReturnsDBHTMLText(): void
    {
        $field = new GridEditorField('GridEditor', 42);
        $this->attachToForm($field);

        self::assertInstanceOf(DBHTMLText::class, $field->FieldHolder());
    }

    /**
     * Attach a field to a minimal Form so Link() resolves.
     */
    private function attachToForm(GridEditorField $field): void
    {
        $form = Form::create(
            Controller::create(),
            'TestForm',
            FieldList::create($field),
            FieldList::create(),
        );
        $form->setFormAction('/test');
    }
}
