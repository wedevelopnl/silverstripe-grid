<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
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
     * answer is memoised for the resolver's lifetime (per request), keyed by
     * block and stage.
     *
     * @var array<string, list<DataObject>>
     */
    private array $cache = [];

    /** @return list<DataObject> */
    public function pagesUsing(SharedBlock $block): array
    {
        return $this->resolve($block, null);
    }

    /**
     * Pages placing this block on LIVE. Not the same question as
     * {@see pagesUsing()}: a placement can exist on one stage only, and it is
     * the live half that a delete takes off the public site immediately.
     *
     * @return list<DataObject>
     */
    public function livePagesUsing(SharedBlock $block): array
    {
        return $this->resolve($block, Versioned::LIVE);
    }

    /** @return int<0, max> */
    public function usageCount(SharedBlock $block): int
    {
        return count($this->pagesUsing($block));
    }

    /** @return int<0, max> */
    public function liveUsageCount(SharedBlock $block): int
    {
        return count($this->livePagesUsing($block));
    }

    /**
     * @param string|null $stage Stage to read on, or null for the ambient one.
     * @return list<DataObject>
     */
    private function resolve(SharedBlock $block, ?string $stage): array
    {
        $blockId = (int) $block->ID;

        if ($blockId <= 0) {
            return [];
        }

        $key = $blockId . ':' . ($stage ?? 'ambient');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $read = fn(): array => $this->collectPages($blockId);

        return $this->cache[$key] = $stage === null
            ? $read()
            : Versioned::withVersionedMode(static function () use ($stage, $read): array {
                Versioned::set_stage($stage);

                return $read();
            });
    }

    /** @return list<DataObject> */
    private function collectPages(int $blockId): array
    {
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

        return array_values($pages);
    }
}
