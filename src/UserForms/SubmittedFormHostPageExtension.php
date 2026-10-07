<?php

declare(strict_types=1);

namespace WeDevelop\Grid\UserForms;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Records the page a form element was submitted from — one shared form block
 * collects submissions from every page that places it. Available to recipient
 * emails as $SubmittedForm.HostPage, since userforms passes the submission
 * into the email data.
 *
 * @extends Extension<SubmittedForm>
 */
class SubmittedFormHostPageExtension extends Extension
{
    /** @var array<string, class-string> */
    private static array $has_one = [
        'HostPage' => SiteTree::class,
    ];

    /**
     * Runs from onBeforeWrite() for a saved submission, and from
     * {@see UserFormEmailDataExtension} for every one: with
     * DisableSaveSubmissions on, userforms never writes the submission.
     */
    public function recordHostPage(): void
    {
        $submission = $this->getOwner();

        if ($submission->isInDB()
            || $submission->HostPageID > 0
            || !is_a($submission->ParentClass, UserFormElement::class, true)
        ) {
            return;
        }

        // Set by the host page's ContentController for the whole request.
        $page = Director::get_current_page();

        if ($page instanceof SiteTree) {
            $submission->HostPageID = (int) $page->ID;
        }
    }

    protected function onBeforeWrite(): void
    {
        $this->recordHostPage();
    }
}
