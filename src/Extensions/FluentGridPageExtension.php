<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use SilverStripe\CMS\Model\SiteTree;
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
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Service\LocalisedSubtreeCloner;
use WeDevelop\Grid\Service\SharedBlockLocaliser;

/**
 * Copies the grid element tree when Fluent localises a page to a new locale.
 *
 * Supports two Fluent code paths:
 * - CopyToLocaleService fires onAfterCopyLocale($from, $to) with explicit source locale
 * - CMS "Copy to other locales" button fires onAfterLocalisedCopy() with detected source
 *
 * Only activates when FluentIsolatedExtension is applied to GridElement and
 * the target locale has no Sections yet (prevents double-copy on re-saves).
 *
 * @extends Extension<SiteTree>
 */
class FluentGridPageExtension extends Extension
{
    /**
     * Fired by CopyToLocaleService — explicit source locale.
     */
    public function onAfterCopyLocale(string $fromLocale, string $toLocale): void
    {
        $this->copyGridFromLocale($fromLocale);
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

        $page = $this->getOwner();

        /** @var positive-int $pageId */
        $pageId = (int) $page->ID;

        $sourceLocale = $this->cloner()->findSourceLocale(
            fn (): int => $this->countRootsInLocale($pageId, $page::class),
            $targetLocale,
        );
        if ($sourceLocale === null) {
            return;
        }

        $this->copyGridFromLocale($sourceLocale);
    }

    private function copyGridFromLocale(string $sourceLocale): void
    {
        if (!GridElement::has_extension(FluentIsolatedExtension::class)) {
            return;
        }

        $page = $this->getOwner();

        if (!$page->hasExtension(GridPageExtension::class)) {
            return;
        }

        /** @var positive-int $pageId */
        $pageId = (int) $page->ID;

        // Idempotency: a re-save must not copy a second time.
        if ($this->countRootsInLocale($pageId, $page::class) > 0) {
            return;
        }

        $targetLocaleRecord = Locale::getCurrentLocale();
        if ($targetLocaleRecord === null) {
            return;
        }

        /** @var positive-int $targetLocaleId */
        $targetLocaleId = (int) $targetLocaleRecord->ID;

        // Suppress auto-scaffolding during duplication — the cascade already
        // copies real children, and auto-scaffold would create extras.
        // FluentState::withState only scopes the locale, not Config, so the
        // override must be captured and restored explicitly in a finally block —
        // otherwise it leaks for the rest of the request and a later DRAFT
        // Section/Row write silently skips scaffolding.
        /** @var bool $priorSectionScaffold */
        $priorSectionScaffold = Section::config()->get('auto_scaffold');
        /** @var bool $priorRowScaffold */
        $priorRowScaffold = Row::config()->get('auto_scaffold');

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        try {
            $cloner = $this->cloner();

            // The clone walk runs in the SOURCE locale, where the originals —
            // and, once written, their clones — are visible.
            $cloned = FluentState::singleton()->withState(
                static function (FluentState $state) use ($sourceLocale, $page, $targetLocaleId, $cloner): array {
                    $state->setLocale($sourceLocale);

                    // Every root element, not just Sections: a shared block
                    // placement is page content too, and cloning it carries the
                    // BlockID across so the new locale points at the same block
                    // — without duplicating the block's own subtree.
                    $roots = GridElement::get()->filter([
                        'ParentID' => $page->ID,
                        'ParentClass' => $page::class,
                    ]);

                    return $cloner->cloneSubtrees($roots, $targetLocaleId)->unwrap();
                }
            );
        } finally {
            Config::modify()->set(Section::class, 'auto_scaffold', $priorSectionScaffold);
            Config::modify()->set(Row::class, 'auto_scaffold', $priorRowScaffold);
        }

        $this->localiseBlocksBehind($cloned);
    }

    /**
     * Give every block this page now places a subtree in the TARGET locale.
     *
     * Localising a page localises the blocks on it, which is what an author
     * expects and what stops a freshly copied page rendering empty frames. The
     * blocks are shared, so this is additive and cross-page: a block that had no
     * content in this locale now has some, and any other page in this locale
     * placing it starts rendering. It can never overwrite a translation —
     * {@see SharedBlockLocaliser} only clones into a locale that holds nothing.
     *
     * Runs OUTSIDE the withState() block above, back in the target locale: the
     * localiser's emptiness guard counts roots in the ACTIVE locale, so calling
     * it in the source locale would find content every time and skip silently.
     *
     * Placements are not only page roots — a row-rooted block sits inside a
     * Section — so this walks every node the clone produced, not just the roots.
     *
     * @param list<GridElement> $cloned
     */
    private function localiseBlocksBehind(array $cloned): void
    {
        $blockIds = [];

        foreach ($cloned as $element) {
            if (!$element instanceof SharedBlockReference) {
                continue;
            }

            $blockId = (int) $element->BlockID;

            if ($blockId > 0) {
                $blockIds[$blockId] = true;
            }
        }

        if ($blockIds === []) {
            return;
        }

        $localiser = Injector::inst()->get(SharedBlockLocaliser::class);

        foreach (SharedBlock::get()->byIDs(array_keys($blockIds)) as $block) {
            $localiser->ensureLocalised($block);
        }
    }

    /**
     * Root elements this page holds in whichever locale is currently active —
     * Sections and shared block placements alike.
     *
     * @param positive-int $pageId
     * @param class-string $pageClass
     * @return int<0, max>
     */
    private function countRootsInLocale(int $pageId, string $pageClass): int
    {
        return GridElement::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => $pageClass,
        ])->count();
    }

    private function cloner(): LocalisedSubtreeCloner
    {
        return Injector::inst()->get(LocalisedSubtreeCloner::class);
    }
}
