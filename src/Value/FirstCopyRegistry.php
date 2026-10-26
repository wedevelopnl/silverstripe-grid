<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Remembers, over one migration run, the first new element written for each
 * legacy element.
 *
 * A legacy element becomes several new elements when its content area is
 * shared by several pages, or once per locale pass on a Fluent site. The first
 * copy takes the legacy element's owned items; every later copy is told which
 * element that was, so it can duplicate them.
 *
 * A page's elements are recorded through {@see commit()} only after its
 * transaction committed: a page that rolled back wrote no copy anybody could
 * point at. Spans the locale passes, so the record carries the locale the copy
 * was written in.
 */
final class FirstCopyRegistry
{
    /** @var array<int, array{id: positive-int, locale: ?non-empty-string}> legacy element id → first copy */
    private array $firstCopies = [];

    /**
     * @param non-empty-string|null $locale the locale pass the page was written in, null without Fluent
     */
    public function commit(MigrationIdMap $page, ?string $locale): void
    {
        foreach ($page->elementIds() as $legacyId => $newElementId) {
            $this->firstCopies[$legacyId] ??= ['id' => $newElementId, 'locale' => $locale];
        }
    }

    /**
     * @return array{id: positive-int, locale: ?non-empty-string}|null
     */
    public function firstCopy(int $legacyId): ?array
    {
        return $this->firstCopies[$legacyId] ?? null;
    }
}
