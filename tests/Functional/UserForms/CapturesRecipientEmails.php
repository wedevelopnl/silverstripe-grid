<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\UserForms;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\UserForms\Control\UserDefinedFormController;

/**
 * Keeps every recipient email userforms builds, as it stands right before
 * sending — the data the recipient's template renders.
 *
 * @extends Extension<UserDefinedFormController>
 */
class CapturesRecipientEmails extends Extension implements TestOnly
{
    /** @var list<Email> */
    public static array $emails = [];

    protected function updateEmail(Email $email): void
    {
        self::$emails[] = $email;
    }
}
