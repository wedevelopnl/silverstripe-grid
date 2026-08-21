<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * TestOnly extension that vetoes SharedBlock::canCreate() and nothing else.
 *
 * Lets a test reach the library as a member who may browse and edit blocks but
 * not add one — a state no permission code produces on its own, since the
 * library's section code grants all three together.
 *
 * @extends Extension<SharedBlock>
 */
class DenyBlockCreateExtension extends Extension implements TestOnly
{
    /** @param array<string, mixed> $context */
    public function canCreate(?Member $member = null, array $context = []): ?bool
    {
        return false;
    }
}
