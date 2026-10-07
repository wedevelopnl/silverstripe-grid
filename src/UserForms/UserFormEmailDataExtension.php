<?php

declare(strict_types=1);

namespace WeDevelop\Grid\UserForms;

use SilverStripe\Core\Extension;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Gives recipient emails $SubmittedForm.HostPage when the submission is not
 * saved: userforms only writes it — and so only fires its onBeforeWrite() —
 * with DisableSaveSubmissions off.
 *
 * @extends Extension<UserDefinedFormController>
 */
class UserFormEmailDataExtension extends Extension
{
    /**
     * @param array<string, mixed> $emailData
     * @param array<string, mixed> $attachments
     */
    protected function updateEmailData(array &$emailData, array &$attachments): void
    {
        $submission = $emailData['SubmittedForm'] ?? null;

        if ($submission instanceof SubmittedForm) {
            $submission->recordHostPage();
        }
    }
}
