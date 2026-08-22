<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use SilverStripe\Core\Injector\Injectable;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;

/**
 * Clones grid subtrees into another locale.
 *
 * Extracted so pages and shared blocks share one copy of the mechanics: both
 * hosts hold a set of root elements and need the identical clone-then-restamp
 * walk. The caller owns the FluentState (the SOURCE locale must be active) and
 * the auto_scaffold suppression around the call, because those differ per host.
 */
class LocalisedSubtreeCloner
{
    use Injectable;

    public function __construct(
        private readonly GridTreeService $treeService,
    ) {
    }

    /**
     * Clone each root — and everything beneath it — into the target locale.
     *
     * Atomic on purpose: a failure part-way would leave clones carrying the
     * SOURCE LocaleID, visible in the wrong locale and invisible in the target
     * one, which no later save repairs because the caller's emptiness check
     * would then see the half-copied roots and decline to run again.
     *
     * Returns every node written, roots and descendants alike, so a caller can
     * act on what the copy actually contains — the page copy uses it to find the
     * shared-block placements it just carried into the new locale.
     *
     * @param iterable<GridElement> $roots
     * @param positive-int $targetLocaleId
     * @return Result<list<GridElement>>
     */
    #[NoDiscard('The Result reports whether the whole clone succeeded and carries the cloned nodes; discarding it hides a partial copy.')]
    public function cloneSubtrees(iterable $roots, int $targetLocaleId): Result
    {
        return Transactional::run(function () use ($roots, $targetLocaleId): Result {
            $written = [];

            foreach ($roots as $root) {
                $clone = $root->duplicate(true);

                // Reload the freshly-written subtree while still in the source
                // locale, where the clones are visible. findDescendants excludes
                // the root, so prepend the clone itself.
                $cloned = [$clone, ...$this->treeService->findDescendants($clone)];

                // duplicate() copies the source LocaleID and
                // FluentIsolatedExtension::onBeforeWrite only auto-assigns when
                // empty, so every node has to be restamped explicitly.
                foreach ($cloned as $element) {
                    $element->LocaleID = $targetLocaleId;
                    $element->write();

                    $written[] = $element;
                }
            }

            return Result::ok($written);
        });
    }

    /**
     * A locale other than $targetLocale that already holds content, preferring
     * the default locale before falling back to any cached locale.
     *
     * Shared by both hosts through $countInLocale, which answers "how many root
     * elements does this locale hold?" for a page or for a block.
     *
     * @param callable(string): int<0, max> $countInLocale
     * @param non-empty-string $targetLocale
     */
    public function findSourceLocale(callable $countInLocale, string $targetLocale): ?string
    {
        $defaultLocale = Locale::getDefault();

        if ($defaultLocale !== null && $defaultLocale->Locale !== $targetLocale && $this->countIn($countInLocale, $defaultLocale->Locale) > 0) {
            return $defaultLocale->Locale;
        }

        foreach (Locale::getCached() as $locale) {
            if ($locale->Locale === $targetLocale) {
                continue;
            }

            if ($this->countIn($countInLocale, $locale->Locale) > 0) {
                return $locale->Locale;
            }
        }

        return null;
    }

    /**
     * @param callable(string): int<0, max> $countInLocale
     * @return int<0, max>
     */
    private function countIn(callable $countInLocale, string $locale): int
    {
        return FluentState::singleton()->withState(
            static function (FluentState $state) use ($countInLocale, $locale): int {
                $state->setLocale($locale);

                return $countInLocale($locale);
            }
        );
    }
}
