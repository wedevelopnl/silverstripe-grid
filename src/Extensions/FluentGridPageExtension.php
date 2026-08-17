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
use WeDevelop\Grid\Service\LocalisedSubtreeCloner;

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
            FluentState::singleton()->withState(
                static function (FluentState $state) use ($sourceLocale, $page, $targetLocaleId, $cloner): void {
                    $state->setLocale($sourceLocale);

                    // Every root element, not just Sections: a shared block
                    // placement is page content too, and cloning it carries the
                    // BlockID across so the new locale points at the same block
                    // — without duplicating the block's own subtree.
                    $roots = GridElement::get()->filter([
                        'ParentID' => $page->ID,
                        'ParentClass' => $page::class,
                    ]);

                    $cloner->cloneSubtrees($roots, $targetLocaleId)->unwrap();
                }
            );
        } finally {
            Config::modify()->set(Section::class, 'auto_scaffold', $priorSectionScaffold);
            Config::modify()->set(Row::class, 'auto_scaffold', $priorRowScaffold);
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
