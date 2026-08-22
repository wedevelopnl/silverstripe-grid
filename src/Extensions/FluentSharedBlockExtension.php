<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Service\SharedBlockLocaliser;

/**
 * Copies a shared block's subtree when Fluent localises the block.
 *
 * The block RECORD is a single cross-locale row — its Title is admin labelling.
 * Its SUBTREE is locale-isolated exactly like every other grid element, so
 * locale X's version of a block is the elements with LocaleID = X hanging off
 * it. Editing a block in locale X therefore updates every page in that locale
 * and no other, which is the same "isolated" semantics as the rest of the
 * module rather than a second localisation strategy.
 *
 * The mechanics live in {@see SharedBlockLocaliser}, which every site that can
 * put a placement into a locale shares — these hooks, the page-copy hooks in
 * {@see FluentGridPageExtension}, and SharedBlockService::place().
 *
 * @extends Extension<SharedBlock>
 */
class FluentSharedBlockExtension extends Extension
{
    /** Fired by CopyToLocaleService — explicit source locale. */
    public function onAfterCopyLocale(string $fromLocale, string $toLocale): void
    {
        $this->localiser()->ensureLocalised($this->getOwner(), $fromLocale);
    }

    /**
     * Fired by FluentExtension::makeLocalisedCopy() during onBeforeWrite —
     * covers the CMS "Copy to other locales" button path. No explicit source,
     * so the localiser picks one that holds content.
     */
    public function onAfterLocalisedCopy(): void
    {
        $this->localiser()->ensureLocalised($this->getOwner());
    }

    private function localiser(): SharedBlockLocaliser
    {
        return Injector::inst()->get(SharedBlockLocaliser::class);
    }
}
