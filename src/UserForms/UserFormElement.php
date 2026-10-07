<?php

declare(strict_types=1);

namespace WeDevelop\Grid\UserForms;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Form\UserForm as UserFormForm;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\UserForm;
use WeDevelop\Grid\Contract\UnavailableElementInterface;
use WeDevelop\Grid\Model\GridElement;

// userforms is optional. trait_exists(), not class_exists(): UserForm is a trait.
if (!trait_exists(UserForm::class)) {
    /**
     * Stand-in declared only while userforms is missing. The class manifest
     * lists this file's class either way, and the framework instantiates every
     * listed DataObject without a class_exists() guard (DbBuild's onAfterBuild()
     * loop, DbDefaults), so leaving the name undeclared fails dev/build. Delete
     * this branch, and raise the framework floor, once that is fixed:
     * https://github.com/silverstripe/silverstripe-framework/issues/12030
     *
     * Same parent and table as the real class below, so the manifest and the
     * schema agree whichever declaration they see. Must stay inert: never
     * offered or created (UnavailableElementInterface), renders nothing.
     */
    class UserFormElement extends GridElement implements UnavailableElementInterface
    {
        private static string $table_name = 'WeDevelop_Grid_UserFormElement';

        /**
         * @param Member|null $member
         * @param array<string, mixed> $context
         */
        #[Override]
        public function canCreate(mixed $member = null, mixed $context = []): bool
        {
            return false;
        }

        #[Override]
        public function forTemplate(): string
        {
            return '';
        }
    }

    return;
}

/**
 * A form built in the CMS with userforms. The element IS the form: fields,
 * recipients and submissions belong to it, so a shared block is how one form
 * is reused across pages.
 *
 * Declares none of the statics the trait declares ($db, $has_many, $defaults,
 * $cascade_*, $extensions, …): a redeclaration is a fatal trait composition
 * error.
 *
 * @method HasManyList<EditableFormField> Fields()
 * @method SiteTree RedirectPage()
 */
class UserFormElement extends GridElement
{
    use UserForm {
        // GridElement::getCMSFields() is typed `: FieldList`; the trait's
        // untyped override would be a fatal incompatible declaration, so it is
        // aliased and reached through the typed method below.
        getCMSFields as private userFormCMSFields;
    }

    private static string $table_name = 'WeDevelop_Grid_UserFormElement';

    private static string $singular_name = 'Form';

    private static string $plural_name = 'Forms';

    private static string $class_description = 'A form built in the CMS; its submissions are kept with the block.';

    private static string $icon = 'font-icon-block-form';

    /** @var array<string, class-string> */
    private static array $has_one = [
        'RedirectPage' => SiteTree::class,
    ];

    #[Override]
    public function getCMSFields(): FieldList
    {
        $fields = $this->userFormCMSFields();

        $fields->addFieldToTab(
            'Root.FormOptions',
            TreeDropdownField::create(
                'RedirectPageID',
                _t(self::class . '.REDIRECT_PAGE', 'After submitting, go to'),
                SiteTree::class,
            )->setDescription(_t(
                self::class . '.REDIRECT_PAGE_DESCRIPTION',
                'Leave empty to show the on-complete message in place of the form.',
            )),
        );

        $this->addHostPageColumn($fields);

        return $fields;
    }

    /** Plain-text count of the fields that collect input; steps, groups and hidden headings excluded. */
    #[Override]
    public function getSummary(): ?string
    {
        $count = $this->Fields()
            ->filterByCallback(static fn(EditableFormField $field): bool => (bool) $field->showInReports())
            ->count();

        if ($count === 0) {
            return null;
        }

        return _t(self::class . '.SUMMARY_FIELDS', '{count} field|{count} fields', ['count' => $count]);
    }

    /**
     * Every userforms URL derives from this: the form action is Link() + 'Form',
     * success redirects to Link('finished'), the replay guard bounces to Link().
     * ContentController::Link(null) passes `true`, hence the bool. The route goes
     * through the page's Link() as its action: a bare Link() drops the homepage's
     * URLSegment, and /grid-form/{id} matches no page.
     */
    public function Link(string|bool|null $action = null): string
    {
        $page = Director::get_current_page();

        if (!$page instanceof SiteTree) {
            return '';
        }

        return $page->Link(Controller::join_links('grid-form', $this->ID, is_string($action) ? $action : null));
    }

    /** The form for the template; null outside a page request, where no route can receive it. */
    public function Form(): UserFormForm|DBHTMLText|null
    {
        $current = Controller::curr();

        if ($current === null || $this->Link() === '') {
            return null;
        }

        $received = $current instanceof UserFormElementController ? $current->receivedMessageFor($this) : null;

        if ($received !== null) {
            return $received;
        }

        $controller = UserDefinedFormController::create($this);
        $controller->setRequest($current->getRequest());

        // Loads userforms' CSS/JS and runs ContentController::init()'s
        // canView() check on this element — see GridElement::canView().
        $controller->doInit();

        return $controller->Form();
    }

    private function addHostPageColumn(FieldList $fields): void
    {
        $grid = $fields->dataFieldByName('Submissions');
        $columns = $grid instanceof GridField
            ? $grid->getConfig()->getComponentByType(GridFieldDataColumns::class)
            : null;

        if (!$columns instanceof GridFieldDataColumns) {
            return;
        }

        $columns->setDisplayFields(
            ['HostPage.Title' => _t(self::class . '.SUBMISSION_PAGE', 'Page')]
            + $columns->getDisplayFields($grid),
        );
    }
}
