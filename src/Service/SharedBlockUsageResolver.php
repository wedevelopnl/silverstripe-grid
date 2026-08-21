<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;

/**
 * Resolves which pages consume a shared block.
 *
 * Counts distinct PAGES, not placements: a page that places the same block in
 * two zones uses it once, and under Fluent the per-locale reference rows of one
 * page collapse to that single page too.
 */
class SharedBlockUsageResolver
{
    use Injectable;

    /**
     * One tree build asks for the same block's usage once per placement, so the
     * answer is memoised for the resolver's lifetime (per request).
     *
     * @var array<int, list<DataObject>>
     */
    private array $cache = [];

    /** @return list<DataObject> */
    public function pagesUsing(SharedBlock $block): array
    {
        $blockId = (int) $block->ID;

        if ($blockId <= 0) {
            return [];
        }

        if (isset($this->cache[$blockId])) {
            return $this->cache[$blockId];
        }

        /** @var array<string, DataObject> $pages */
        $pages = [];

        foreach (SharedBlockReference::get()->filter(['BlockID' => $blockId]) as $reference) {
            $page = $reference->getPage();

            if ($page === null) {
                continue;
            }

            // Page IDs collide across polymorphic namespaces, so dedupe on the
            // composite key the rest of the module keys parents by.
            $pages[$page::class . ':' . $page->ID] = $page;
        }

        return $this->cache[$blockId] = array_values($pages);
    }

    /** @return int<0, max> */
    public function usageCount(SharedBlock $block): int
    {
        return count($this->pagesUsing($block));
    }
}
