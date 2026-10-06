<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\UserForms;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\EditableFormField\EditableFormHeading;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\Recipient\EmailRecipient;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Service\GridNodeMapper;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\UserForms\UserFormElement;

#[CoversClass(UserFormElement::class)]
final class UserFormElementTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testThePickerOffersTheFormInsideAColumn(): void
    {
        $types = Injector::inst()->get(GridNodeMapper::class)->allowedTypesByContainerType();

        self::assertArrayHasKey(UserFormElement::class, $types['column']);
        self::assertSame('Form', $types['column'][UserFormElement::class]['label']);
        self::assertSame('font-icon-block-form', $types['column'][UserFormElement::class]['icon']);
    }

    public function testSummaryCountsOnlyFieldsThatCollectInput(): void
    {
        $form = $this->form();
        $this->textField($form, 'Name');
        $this->textField($form, 'Email');
        $heading = EditableFormHeading::create();
        $heading->Title = 'About you';
        $heading->HideFromReports = true;
        $form->Fields()->add($heading);

        // The initial EditableFormStep (created on write) and the hidden heading are not counted.
        self::assertSame('2 fields', $form->getSummary());
    }

    public function testSummaryUsesTheSingularForOneField(): void
    {
        $form = $this->form();
        $this->textField($form, 'Name');

        self::assertSame('1 field', $form->getSummary());
    }

    public function testSummaryIsEmptyForAFormWithoutInputFields(): void
    {
        self::assertNull($this->form()->getSummary());
    }

    public function testCmsFieldsCombineTheGridTitleTheFormEditorAndTheRedirect(): void
    {
        $fields = $this->form()->getCMSFields();

        self::assertNotNull($fields->fieldByName('Root.Main.TitleSettings'));
        self::assertInstanceOf(GridField::class, $fields->dataFieldByName('Fields'));
        self::assertNotNull($fields->dataFieldByName('RedirectPageID'));

        $submissions = $fields->dataFieldByName('Submissions');
        self::assertInstanceOf(GridField::class, $submissions);
        $columns = $submissions->getConfig()->getComponentByType(GridFieldDataColumns::class);
        self::assertInstanceOf(GridFieldDataColumns::class, $columns);
        self::assertArrayHasKey('HostPage.Title', $columns->getDisplayFields($submissions));
    }

    public function testPublishingThePagePublishesTheFormFields(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $form = $this->form($this->columnOn($page));
        $field = $this->textField($form, 'Name');

        $page->publishRecursive();

        self::assertTrue($field->isPublished());
    }

    public function testPublishingTheSharedBlockPublishesTheFormFields(): void
    {
        $block = GridTreeFactory::sharedBlock('Contact');
        $column = GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($block)));
        $field = $this->textField($this->form($column), 'Name');

        $block->publishRecursive();

        self::assertTrue($field->isPublished());
    }

    public function testDuplicateCopiesFieldsAndRecipientsButNotSubmissions(): void
    {
        $form = $this->form();
        $this->textField($form, 'Name');
        $recipient = EmailRecipient::create();
        $recipient->EmailAddress = 'office@example.com';
        $recipient->EmailFrom = 'site@example.com';
        $recipient->EmailSubject = 'New submission';
        $form->EmailRecipients()->add($recipient);
        $submission = SubmittedForm::create();
        $submission->ParentID = $form->ID;
        $submission->ParentClass = UserFormElement::class;
        $submission->write();

        $copy = $form->duplicate();
        self::assertInstanceOf(UserFormElement::class, $copy);

        // Compare input fields only: the copy's own write may add an initial
        // EditableFormStep before userforms' onAfterDuplicate() copies the source's.
        $copied = $copy->Fields()->filter('ClassName', EditableTextField::class);
        self::assertSame(['Name'], $copied->column('Title'));
        self::assertNotEquals(
            $form->Fields()->filter('ClassName', EditableTextField::class)->column('ID'),
            $copied->column('ID'),
            'The copy must own new field records',
        );
        self::assertCount(1, $copy->EmailRecipients());
        self::assertCount(0, $copy->Submissions());
    }

    private function columnOn(Page $page): Column
    {
        return GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($page)));
    }

    private function form(?Column $column = null): UserFormElement
    {
        $column ??= $this->columnOn($this->objFromFixture(Page::class, 'test_page'));

        $form = UserFormElement::create();
        $form->Title = 'Contact';
        $form->ParentID = $column->ID;
        $form->ParentClass = $column::class;
        $form->write();

        return $form;
    }

    private function textField(UserFormElement $form, string $title): EditableFormField
    {
        $field = EditableTextField::create();
        $field->Title = $title;
        $form->Fields()->add($field);

        return $field;
    }
}
