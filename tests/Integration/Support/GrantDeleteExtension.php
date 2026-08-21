<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that grants GridElement::canDelete() unconditionally.
 *
 * The mirror of {@see VetoBlockDeleteExtension}: it exists to prove that the
 * block-root protection outranks the extension hook. An extension answering
 * `true` decides every other element's deletion, so a root that stays
 * undeletable can only be the structural guard above the hook.
 *
 * @extends Extension<GridElement>
 */
class GrantDeleteExtension extends Extension implements TestOnly
{
    /** @param array<string, mixed> $context */
    public function canDelete(?Member $member = null, array $context = []): ?bool
    {
        return true;
    }
}
