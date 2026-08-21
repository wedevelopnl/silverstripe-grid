<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * TestOnly extension that vetoes SharedBlock::canDelete() and nothing else.
 *
 * It exists to separate the two permissions a block answers with the same code,
 * so a test can tell which one {@see \WeDevelop\Grid\Model\GridElement::canDelete()}
 * actually consults for an element inside a block. Without a veto the two agree
 * for every member and the delegation cannot be observed.
 *
 * @extends Extension<SharedBlock>
 */
class VetoBlockDeleteExtension extends Extension implements TestOnly
{
    /** @param array<string, mixed> $context */
    public function canDelete(?Member $member = null, array $context = []): ?bool
    {
        return false;
    }
}
