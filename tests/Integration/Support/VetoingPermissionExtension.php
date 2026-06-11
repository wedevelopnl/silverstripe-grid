<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that vetoes every can* permission hook by returning false.
 *
 * Used to prove {@see GridElement} routes its permission checks through
 * {@see DataObject::extendedCan()} — an extension that returns false must be
 * honoured before the page/Permission fallback runs. Each hook returns false
 * (an explicit deny), which {@see DataObject::extendedCan()} surfaces via
 * `min()` over the non-null results.
 *
 * @extends Extension<GridElement>
 */
class VetoingPermissionExtension extends Extension implements TestOnly
{
    /** @param array<string, mixed> $context */
    public function canView(?Member $member = null, array $context = []): bool
    {
        return false;
    }

    /** @param array<string, mixed> $context */
    public function canEdit(?Member $member = null, array $context = []): bool
    {
        return false;
    }

    /** @param array<string, mixed> $context */
    public function canDelete(?Member $member = null, array $context = []): bool
    {
        return false;
    }

    /** @param array<string, mixed> $context */
    public function canCreate(?Member $member = null, array $context = []): bool
    {
        return false;
    }
}
