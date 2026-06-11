<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that fails write-time validation for any element whose
 * Title ends with "copy".
 *
 * Used to force the final write inside
 * {@see \WeDevelop\Grid\Service\GridElementService::duplicateElementTo()} to
 * throw a ValidationException *after* the C1 ownership and C2 hierarchy checks
 * have passed — the duplicated clone always carries a generated "… copy" title,
 * while the original source element does not. This drives the deep-duplicate
 * write down its failure path so the transaction rollback can be asserted.
 *
 * @extends Extension<GridElement>
 */
class RejectCopyTitleExtension extends Extension implements TestOnly
{
    public function updateValidate(ValidationResult $result): void
    {
        $title = (string) $this->getOwner()->Title;

        if (str_ends_with($title, 'copy')) {
            $result->addError('Copies are rejected by the test extension.');
        }
    }
}
