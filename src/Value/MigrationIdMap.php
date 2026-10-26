<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Carries the id/sort maps threaded through a single page's migration.
 *
 * The draft writer ({@see \WeDevelop\Grid\Migration\Service\DraftHierarchyWriter})
 * populates the legacy-id-keyed maps; the live publisher
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

    /** @var array<int, positive-int> legacy row id → new Row ID */
    private array $oldRowToNewRowId = [];

    /** @var array<int, string> legacy row id → the default title its new Row's position gives it */
    private array $oldRowToDefaultTitle = [];

    /** @var array<int, positive-int> legacy row id → new Section ID it supplied the fields of */
    private array $oldRowToNewSectionId = [];

    /** @var array<int, true> container ID → published flag (publisher-internal) */
    private array $publishedContainers = [];

    /**
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

    /** @param positive-int $rowId */
    public function recordRow(int $legacyRowId, int $rowId, string $defaultTitle): void
    {
        $this->oldRowToNewRowId[$legacyRowId] = $rowId;
        $this->oldRowToDefaultTitle[$legacyRowId] = $defaultTitle;
    }

    /** @param positive-int $sectionId */
    public function recordSection(int $legacyRowId, int $sectionId): void
    {
        $this->oldRowToNewSectionId[$legacyRowId] = $sectionId;
    }

    /** @return positive-int|null */
    public function newRowId(int $legacyRowId): ?int
    {
        return $this->oldRowToNewRowId[$legacyRowId] ?? null;
    }

    public function rowDefaultTitle(int $legacyRowId): string
    {
        return $this->oldRowToDefaultTitle[$legacyRowId];
    }

    /** @return positive-int|null */
    public function newSectionId(int $legacyRowId): ?int
    {
        return $this->oldRowToNewSectionId[$legacyRowId] ?? null;
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
