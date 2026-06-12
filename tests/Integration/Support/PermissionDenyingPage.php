<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use Override;
use Page;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;

/**
 * Test-only page that denies every can* permission, regardless of the member.
 *
 * Used to prove {@see \WeDevelop\Grid\Model\GridElement} delegates its
 * permission checks to the owning page: an element under this page must be
 * denied even when the member holds CMS access (which the orphan fallback
 * `Permission::check('CMS_ACCESS', ...)` would otherwise grant).
 */
class PermissionDenyingPage extends Page implements TestOnly
{
    private static string $table_name = 'GridTestPermissionDenyingPage';

    #[Override]
    public function canView($member = null): bool
    {
        return false;
    }

    #[Override]
    public function canEdit($member = null): bool
    {
        return false;
    }

    #[Override]
    public function canDelete($member = null): bool
    {
        return false;
    }
}
