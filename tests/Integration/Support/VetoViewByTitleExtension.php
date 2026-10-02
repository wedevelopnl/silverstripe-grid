<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that vetoes canView() for elements whose title is listed in
 * `hidden_titles` (by default only {@see HIDDEN_TITLE}) and abstains (null) for
 * every other element.
 *
 * Unlike {@see VetoingPermissionExtension}, which denies everything, this allows a
 * sibling list to mix viewable and non-viewable elements. That distinction matters:
 * with a uniform denial a `continue` and a `break` in the permission filter produce
 * identical output, so only a partial denial can pin the skip-don't-stop behaviour.
 *
 * @extends Extension<GridElement>
 */
class VetoViewByTitleExtension extends Extension implements TestOnly
{
    public const string HIDDEN_TITLE = 'hidden-from-view';

    /** @var list<string> */
    private static array $hidden_titles = [self::HIDDEN_TITLE];

    /** @param array<string, mixed> $context */
    public function canView(?Member $member = null, array $context = []): ?bool
    {
        /** @var list<string> $hidden */
        $hidden = Config::inst()->get(self::class, 'hidden_titles');

        return in_array($this->getOwner()->Title, $hidden, true) ? false : null;
    }
}
