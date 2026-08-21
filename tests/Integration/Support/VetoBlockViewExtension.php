<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * TestOnly extension that vetoes SharedBlock::canView().
 *
 * Stands in for the project extension the library's permission methods invite
 * via extendedCan(): it is the only way to produce a member who holds CMS
 * access yet may not see a particular block, which is what the read and place
 * endpoints have to honour.
 *
 * @extends Extension<SharedBlock>
 */
class VetoBlockViewExtension extends Extension implements TestOnly
{
    /** @param array<string, mixed> $context */
    public function canView(?Member $member = null, array $context = []): ?bool
    {
        return false;
    }
}
