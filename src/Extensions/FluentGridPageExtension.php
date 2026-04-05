<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

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

        /** @var SiteTree $page */
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

        /** @var SiteTree $page */
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
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        FluentState::singleton()->withState(function (FluentState $state) use ($sourceLocale, $page, $targetLocaleId): void {
            $state->setLocale($sourceLocale);

            foreach ($page->Sections() as $section) {
                /** @var Section $clone */
                $clone = $section->duplicate(true);

                // Collect all cloned elements while in source locale (where they're visible)
                $allCloned = $this->collectTree($clone);

                // Reassign all LocaleIDs to the target locale.
                // FluentIsolatedExtension::onBeforeWrite only auto-assigns when empty,
                // so we must set it explicitly since duplicate() copies the source LocaleID.
                foreach ($allCloned as $element) {
                    $element->LocaleID = $targetLocaleId;
                    $element->write();
                }
            }
        });
    }

    /**
     * Recursively collects all elements in the tree starting from root.
     * Must be called in the locale context where the elements are visible.
     *
     * @return list<GridElement>
     */
    private function collectTree(GridElement $root): array
    {
        $elements = [$root];

        if ($root instanceof ContainerInterface && $root->hasChildren()) {
            /** @var GridElement $child */
            foreach ($root->getChildren() as $child) {
                $elements = array_merge($elements, $this->collectTree($child));
            }
        }

        return $elements;
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
