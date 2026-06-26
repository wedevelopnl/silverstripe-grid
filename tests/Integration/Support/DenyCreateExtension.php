<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that vetoes ONLY the canCreate permission hook.
 *
 * Unlike {@see VetoingPermissionExtension} (which vetoes every can* hook),
 * this leaves canView/canEdit/canDelete unopinionated so a create request
 * passes the parent's canEdit() gate and is rejected solely by the new
 * canCreate() gate on the element being created.
 *
 * @extends Extension<GridElement>
 */
class DenyCreateExtension extends Extension implements TestOnly
{
    /** @param array<string, mixed> $context */
    public function canCreate(?Member $member = null, array $context = []): bool
    {
        return false;
    }
}
