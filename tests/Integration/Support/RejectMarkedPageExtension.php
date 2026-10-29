<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;

/**
 * TestOnly extension that fails write-time validation for any page whose
 * Title equals the {@see self::FAIL_MARKER} sentinel.
 *
 * The marker is injected via raw SQL after the page is written, so only a
 * later write of that page trips it — the page-draft step of a shared block
 * delete, whose rollback is what the consuming test asserts.
 *
 * @extends Extension<SiteTree>
 */
class RejectMarkedPageExtension extends Extension implements TestOnly
{
    public const string FAIL_MARKER = 'FAIL_PAGE_WRITE';

    public const string MESSAGE = 'Marked page rejected by the test extension.';

    public function updateValidate(ValidationResult $result): void
    {
        if ((string) $this->getOwner()->Title === self::FAIL_MARKER) {
            $result->addError(self::MESSAGE);
        }
    }
}
