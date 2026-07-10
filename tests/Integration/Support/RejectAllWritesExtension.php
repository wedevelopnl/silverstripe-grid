<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that fails write-time validation for every GridElement.
 *
 * Used to force each per-page migration down its failure path so the orchestrator's
 * cross-locale failure accumulation is observable.
 *
 * @extends Extension<GridElement>
 */
class RejectAllWritesExtension extends Extension implements TestOnly
{
    public function updateValidate(ValidationResult $result): void
    {
        $result->addError('Rejected by the test extension.');
    }
}
