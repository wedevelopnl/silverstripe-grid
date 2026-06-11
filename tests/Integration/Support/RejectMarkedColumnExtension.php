<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that fails write-time validation for any element whose
 * ExtraClass equals the {@see self::FAIL_MARKER} sentinel.
 *
 * Used to force a mid-loop write failure inside
 * {@see \WeDevelop\Grid\Service\GridSettingsService::resetOverrides()}. The
 * marker is injected via raw SQL after the column is created (so the initial
 * write succeeds), then the reset write re-validates and fails — driving the
 * loop down its err path so the enclosing-transaction rollback can be asserted.
 *
 * @extends Extension<GridElement>
 */
class RejectMarkedColumnExtension extends Extension implements TestOnly
{
    public const string FAIL_MARKER = 'FAIL_RESET';

    public function updateValidate(ValidationResult $result): void
    {
        if ((string) $this->getOwner()->ExtraClass === self::FAIL_MARKER) {
            $result->addError('Marked column rejected by the test extension.');
        }
    }
}
