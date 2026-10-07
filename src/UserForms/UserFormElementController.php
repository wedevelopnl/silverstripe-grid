<?php

declare(strict_types=1);

namespace WeDevelop\Grid\UserForms;

use Override;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Form\UserForm as UserFormForm;

/**
 * Serves one form element under its host page: /{page}/grid-form/{id}/{action}.
 * The form itself, processing and the replay guard stay userforms' own,
 * driven through a UserDefinedFormController for the element.
 */
class UserFormElementController extends Controller
{
    /** @var list<string> */
    private static array $allowed_actions = ['index', 'Form', 'finished'];

    private ?DBHTMLText $receivedMessage = null;

    private ?UserDefinedFormController $userFormController = null;

    public function __construct(
        private readonly SiteTree $hostPage,
        private readonly UserFormElement $element,
    ) {
        parent::__construct();
    }

    /** userforms bounces to the base route (Link()) when its replay guard fails. */
    public function index(): HTTPResponse
    {
        return $this->redirect($this->hostPage->Link());
    }

    public function Form(): UserFormForm
    {
        return $this->userFormController()->Form();
    }

    public function finished(): HTTPResponse|DBHTMLText
    {
        // userforms' finished() runs the session guard, then renders the
        // received template with its $Submission/$Link data and the
        // updateReceivedFormSubmissionData hook into Content.
        $finished = $this->userFormController()->finished();

        if ($finished instanceof HTTPResponse) {
            return $finished;
        }

        $redirectPage = $this->element->RedirectPage();

        if ($redirectPage->exists()) {
            return $this->redirect($redirectPage->Link());
        }

        // Render the host page while this controller stays current:
        // UserFormElement::Form() asks it receivedMessageFor() and shows the
        // message in place of the form. Returning the page controller
        // instead would make IT current and hide that answer.
        /** @var DBHTMLText $receivedMessage renderWith() output, set by finished() */
        $receivedMessage = $finished->obj('Content');
        $this->receivedMessage = $receivedMessage;
        $page = ModelAsController::controller_for($this->hostPage);
        $page->setRequest($this->getRequest());
        $page->doInit();

        return $page->getViewer('index')->process($page);
    }

    public function receivedMessageFor(UserFormElement $element): ?DBHTMLText
    {
        return (int) $element->ID === (int) $this->element->ID ? $this->receivedMessage : null;
    }

    #[Override]
    public function Link($action = null): string
    {
        return $this->element->Link(is_string($action) ? $action : null);
    }

    private function userFormController(): UserDefinedFormController
    {
        if ($this->userFormController === null) {
            $this->userFormController = UserDefinedFormController::create($this->element);
            $this->userFormController->setRequest($this->getRequest());
        }

        return $this->userFormController;
    }
}
