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
use WeDevelop\Grid\Service\GridTreeService;
use WeDevelop\Grid\Service\Transactional;
use WeDevelop\Grid\Value\Result;

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

        $sourceLocale = $this->findSourceLocale($pageId, $page::class, $targetLocale);
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

        // Use a direct query instead of the has_many relation to avoid the
        // relation cache returning stale data from the source locale context.
        $targetSectionCount = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => $page::class,
        ])->count();

        if ($targetSectionCount > 0) {
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
            $treeService = Injector::inst()->get(GridTreeService::class);

            // Atomic: each section is cloned and then has every node in its
            // subtree rewritten to the target locale. A failure part-way leaves
            // clones carrying the SOURCE LocaleID — visible in the wrong locale
            // and invisible in the target one — which no later save repairs,
            // because the target-locale emptiness check above then sees the
            // half-copied sections and declines to run again.
            Transactional::run(static function () use ($sourceLocale, $page, $targetLocaleId, $treeService): Result {
                FluentState::singleton()->withState(function (FluentState $state) use ($sourceLocale, $page, $targetLocaleId, $treeService): void {
                    $state->setLocale($sourceLocale);

                    foreach ($page->Sections() as $section) {
                        $clone = $section->duplicate(true);

                        // Reload the freshly-written subtree from the DB while still in
                        // the source locale (where the clones are visible). The clone
                        // itself is prepended — findDescendants excludes the root.
                        $allCloned = [$clone, ...$treeService->findDescendants($clone)];

                        // Reassign all LocaleIDs to the target locale.
                        // FluentIsolatedExtension::onBeforeWrite only auto-assigns when empty,
                        // so we must set it explicitly since duplicate() copies the source LocaleID.
                        foreach ($allCloned as $element) {
                            $element->LocaleID = $targetLocaleId;
                            $element->write();
                        }
                    }
                });

                return Result::ok(null);
            })->unwrap();
        } finally {
            Config::modify()->set(Section::class, 'auto_scaffold', $priorSectionScaffold);
            Config::modify()->set(Row::class, 'auto_scaffold', $priorRowScaffold);
        }
    }

    /**
     * Finds a locale that has Sections for the given page.
     * Tries the default locale first, then iterates all cached locales.
     *
     * @param positive-int $pageId
     * @param class-string $pageClass
     * @param non-empty-string $targetLocale Locale to exclude
     */
    private function findSourceLocale(int $pageId, string $pageClass, string $targetLocale): ?string
    {
        $defaultLocale = Locale::getDefault();

        if ($defaultLocale !== null && $defaultLocale->Locale !== $targetLocale) {
            $count = $this->countSectionsInLocale($defaultLocale->Locale, $pageId, $pageClass);
            if ($count > 0) {
                return $defaultLocale->Locale;
            }
        }

        foreach (Locale::getCached() as $locale) {
            if ($locale->Locale === $targetLocale) {
                continue;
            }

            $count = $this->countSectionsInLocale($locale->Locale, $pageId, $pageClass);
            if ($count > 0) {
                return $locale->Locale;
            }
        }

        return null;
    }

    /**
     * @param non-empty-string $locale
     * @param positive-int $pageId
     * @param class-string $pageClass
     * @return int<0, max>
     */
    private function countSectionsInLocale(string $locale, int $pageId, string $pageClass): int
    {
        return FluentState::singleton()->withState(
            function (FluentState $state) use ($locale, $pageId, $pageClass): int {
                $state->setLocale($locale);

                return Section::get()->filter([
                    'ParentID' => $pageId,
                    'ParentClass' => $pageClass,
                ])->count();
            }
        );
    }
}
