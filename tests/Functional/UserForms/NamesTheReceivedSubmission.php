<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\UserForms;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Rewrites the on-complete message from the data userforms hands the
 * received template, so a test can see both the hook and $Submission arrive.
 *
 * @extends Extension<UserDefinedFormController>
 */
class NamesTheReceivedSubmission extends Extension implements TestOnly
{
    /** @param array<string, mixed> $data */
    protected function updateReceivedFormSubmissionData(array &$data): void
    {
        $submission = $data['Submission'] ?? null;
        $data['OnCompleteMessage'] = $submission instanceof SubmittedForm
            ? sprintf('Received submission %d', $submission->ID)
            : 'Received without a submission';
    }
}
