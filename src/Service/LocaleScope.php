<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\Injector\Injectable;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;

/**
 * Runs an operation in every locale that can hold grid content.
 *
 * A shared block is ONE record for the whole site while its subtree and its
 * placements are stored per locale, so anything that acts on the block as a
 * whole — deleting it, counting where it is used — has to visit each locale in
 * turn; a plain query only ever sees the active one.
 *
 * Safe to call unconditionally: without Fluent, or without locale isolation on
 * GridElement, the operation runs once in the ambient state. The Fluent class
 * references are strings at compile time and are only dereferenced past that
 * guard, as in {@see SharedBlockLocaliser}.
 */
final class LocaleScope
{
    use Injectable;

    /**
     * Run $callback with each locale active, the default locale first.
     *
     * @template T
     * @param callable(?Locale): T $callback Receives the active locale, or null
     *                                       on the single pass without Fluent.
     * @return array<string, T> Results keyed by locale code; '' for the pass
     *                          without Fluent.
     */
    public function each(callable $callback): array
    {
        if (!$this->isLocalised()) {
            return ['' => $callback(null)];
        }

        $results = [];

        foreach ($this->locales() as $code => $locale) {
            $results[$code] = FluentState::singleton()->withState(
                static function (FluentState $state) use ($code, $locale, $callback): mixed {
                    $state->setLocale($code);

                    return $callback($locale);
                },
            );
        }

        return $results;
    }

    /** Whether grid content is stored per locale on this site. */
    public function isLocalised(): bool
    {
        return class_exists(FluentState::class)
            && GridElement::has_extension(FluentIsolatedExtension::class);
    }

    /** @return array<string, Locale> */
    private function locales(): array
    {
        $locales = [];
        $default = Locale::getDefault();

        if ($default !== null) {
            $locales[(string) $default->Locale] = $default;
        }

        foreach (Locale::getCached() as $locale) {
            $locales[(string) $locale->Locale] ??= $locale;
        }

        return $locales;
    }
}
