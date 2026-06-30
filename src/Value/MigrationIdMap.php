<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Carries the id/sort maps threaded through a single page's migration.
 *
 * The draft writer ({@see \WeDevelop\Grid\Migration\Service\DraftHierarchyWriter})
 * populates the three legacy-id-keyed maps; the live publisher
 * ({@see \WeDevelop\Grid\Migration\Service\LivePublisher}) reads them to publish
 * shared draft records to live. `publishedContainers` is publisher-internal
 * bookkeeping (dedupes writeToStage(LIVE) calls) and starts empty.
 *
 * Mutable by design — the writer fills it during the draft pass and the
 * publisher reads/extends it during the live pass within one transaction.
 */
final class MigrationIdMap
{
    /** @var array<int, positive-int> legacy element id → new element ID */
    private array $oldToNewElementId = [];

    /** @var array<int, positive-int> legacy element id → new Column ID */
    private array $oldToNewColumnId = [];

    /** @var array<int, positive-int> legacy element id → column-local draft Sort */
    private array $oldToDraftSort = [];

    /** @var array<int, true> container ID → published flag (publisher-internal) */
    private array $publishedContainers = [];

    /**
     * Record a freshly-written content element.
     *
     * @param positive-int $newElementId
     * @param positive-int $columnId
     * @param positive-int $draftSort
     */
    public function recordElement(int $legacyId, int $newElementId, int $columnId, int $draftSort): void
    {
        $this->oldToNewElementId[$legacyId] = $newElementId;
        $this->oldToNewColumnId[$legacyId] = $columnId;
        $this->oldToDraftSort[$legacyId] = $draftSort;
    }

    public function hasElement(int $legacyId): bool
    {
        return \array_key_exists($legacyId, $this->oldToNewElementId);
    }

    /** @return positive-int */
    public function newElementId(int $legacyId): int
    {
        return $this->oldToNewElementId[$legacyId];
    }

    /** @return positive-int */
    public function newColumnId(int $legacyId): int
    {
        return $this->oldToNewColumnId[$legacyId];
    }

    /** @return positive-int */
    public function draftSort(int $legacyId): int
    {
        return $this->oldToDraftSort[$legacyId];
    }

    public function isContainerPublished(int $containerId): bool
    {
        return \array_key_exists($containerId, $this->publishedContainers);
    }

    /** @param positive-int $containerId */
    public function markContainerPublished(int $containerId): void
    {
        $this->publishedContainers[$containerId] = true;
    }
}
