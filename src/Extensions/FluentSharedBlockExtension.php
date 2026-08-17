<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Service\LocalisedSubtreeCloner;

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
 * Mirrors {@see FluentGridPageExtension}, sharing its clone mechanics through
 * {@see LocalisedSubtreeCloner}.
 *
 * @extends Extension<SharedBlock>
 */
class FluentSharedBlockExtension extends Extension
{
    /** Fired by CopyToLocaleService — explicit source locale. */
    public function onAfterCopyLocale(string $fromLocale, string $toLocale): void
    {
        $this->copySubtreeFromLocale($fromLocale);
    }

    /**
     * Fired by FluentExtension::makeLocalisedCopy() during onBeforeWrite —
     * covers the CMS "Copy to other locales" button path.
     */
    public function onAfterLocalisedCopy(): void
    {
        $targetLocale = FluentState::singleton()->getLocale();
        if ($targetLocale === null || $targetLocale === '') {
            return;
        }

        /** @var positive-int $blockId */
        $blockId = (int) $this->getOwner()->ID;

        $sourceLocale = $this->cloner()->findSourceLocale(
            fn (): int => $this->countRootsInLocale($blockId),
            $targetLocale,
        );

        if ($sourceLocale === null) {
            return;
        }

        $this->copySubtreeFromLocale($sourceLocale);
    }

    private function copySubtreeFromLocale(string $sourceLocale): void
    {
        if (!GridElement::has_extension(FluentIsolatedExtension::class)) {
            return;
        }

        $block = $this->getOwner();

        /** @var positive-int $blockId */
        $blockId = (int) $block->ID;

        // Idempotency: a re-save must not copy a second time.
        if ($this->countRootsInLocale($blockId) > 0) {
            return;
        }

        $targetLocaleRecord = Locale::getCurrentLocale();
        if ($targetLocaleRecord === null) {
            return;
        }

        /** @var positive-int $targetLocaleId */
        $targetLocaleId = (int) $targetLocaleRecord->ID;

        // The cascade copies real children, so auto-scaffolding would add
        // extras. Captured and restored explicitly: FluentState scopes the
        // locale, not Config, so an unrestored override would leak for the rest
        // of the request and silently skip scaffolding on a later write.
        /** @var bool $priorSectionScaffold */
        $priorSectionScaffold = Section::config()->get('auto_scaffold');
        /** @var bool $priorRowScaffold */
        $priorRowScaffold = Row::config()->get('auto_scaffold');

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        try {
            $cloner = $this->cloner();

            FluentState::singleton()->withState(
                static function (FluentState $state) use ($sourceLocale, $block, $targetLocaleId, $cloner): void {
                    $state->setLocale($sourceLocale);

                    $cloner->cloneSubtrees($block->RootElements(), $targetLocaleId)->unwrap();
                }
            );
        } finally {
            Config::modify()->set(Section::class, 'auto_scaffold', $priorSectionScaffold);
            Config::modify()->set(Row::class, 'auto_scaffold', $priorRowScaffold);
        }
    }

    /**
     * Root elements this block holds in whichever locale is currently active.
     *
     * @param positive-int $blockId
     * @return int<0, max>
     */
    private function countRootsInLocale(int $blockId): int
    {
        return GridElement::get()->filter([
            'ParentID' => $blockId,
            'ParentClass' => SharedBlock::class,
        ])->count();
    }

    private function cloner(): LocalisedSubtreeCloner
    {
        return Injector::inst()->get(LocalisedSubtreeCloner::class);
    }
}
