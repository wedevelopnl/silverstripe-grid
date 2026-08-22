<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * Materialises a block's subtree in the active locale.
 *
 * The invariant it holds: a placement in locale L implies the block has content
 * in locale L. Without it an author who copies a page to a new locale gets
 * placements that resolve nothing and render as empty frames, with no signal
 * that the fix is to localise each block by hand in the library.
 *
 * Called from every site that can put a placement into a locale — the Fluent
 * page-copy hooks and {@see SharedBlockService::place()} — and from the block's
 * own copy-to-locale hooks. The idempotency guard lives here rather than at
 * each call site, so two pages placing the same block cannot produce two
 * subtrees for one locale.
 *
 * Safe to call unconditionally: without Fluent, or without locale isolation on
 * GridElement, it is a no-op. The Fluent class references are strings at
 * compile time and are only dereferenced past that guard.
 */
class SharedBlockLocaliser
{
    use Injectable;

    /**
     * Give $block a subtree in the ACTIVE locale if it has none.
     *
     * $sourceLocale is the explicit source used by the block's own copy-to-locale
     * hook; left null, a locale that already holds content is chosen, preferring
     * the default one.
     */
    public function ensureLocalised(SharedBlock $block, ?string $sourceLocale = null): void
    {
        if (!GridElement::has_extension(FluentIsolatedExtension::class)) {
            return;
        }

        $blockId = (int) $block->ID;

        if ($blockId <= 0) {
            return;
        }

        // Idempotency: this locale already has its own subtree. Also what stops
        // a second page copy from forking content the author has since
        // translated — the clone only ever runs into an empty locale.
        if ($this->countRootsInLocale($blockId) > 0) {
            return;
        }

        $targetLocaleRecord = Locale::getCurrentLocale();

        if ($targetLocaleRecord === null) {
            return;
        }

        /** @var positive-int $targetLocaleId */
        $targetLocaleId = (int) $targetLocaleRecord->ID;
        $targetLocale = (string) $targetLocaleRecord->Locale;

        if ($sourceLocale === null) {
            $sourceLocale = $this->cloner()->findSourceLocale(
                fn (): int => $this->countRootsInLocale($blockId),
                $targetLocale,
            );
        }

        // No locale holds content for this block: it is empty everywhere, and
        // there is nothing to copy. Not an error — an author may place a block
        // they have not filled in yet.
        if ($sourceLocale === null || $sourceLocale === $targetLocale) {
            return;
        }

        $this->cloneFrom($block, $sourceLocale, $targetLocaleId);
    }

    /** @param positive-int $targetLocaleId */
    private function cloneFrom(SharedBlock $block, string $sourceLocale, int $targetLocaleId): void
    {
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
     * Resolved lazily rather than injected: LocalisedSubtreeCloner needs
     * explicit constructor wiring that only exists in the Fluent-gated config,
     * so a constructor dependency would make this service unconstructible on a
     * site without Fluent — where every public method is a no-op anyway.
     */
    private function cloner(): LocalisedSubtreeCloner
    {
        return Injector::inst()->get(LocalisedSubtreeCloner::class);
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
}
