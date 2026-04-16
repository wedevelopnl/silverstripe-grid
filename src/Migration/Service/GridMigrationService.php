<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Orchestrates the full migration from legacy elemental tables to the new grid hierarchy.
 *
 * Processes each eligible page: reads legacy elements, maps them through the
 * configured strategy into Section/Row/Column hierarchy, writes new records
 * with proper Versioned stage handling, and maintains old→new ID mappings
 * for live reconciliation.
 */
final class GridMigrationService
{
    use Extensible;

    public function __construct(
        private readonly LegacyDataReader $reader,
        private readonly FieldMapper $mapper,
        private readonly RowMappingStrategy $strategy,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Run the migration for all eligible pages (or a filtered subset).
     *
     * @param string $defaultViewport Old viewport key used as default (e.g. 'MD')
     * @param string $zone Zone name for created Sections
     * @param array<string, string> $viewportKeyMap Old viewport key → new key (e.g. 'MD' → 'md')
     * @param bool $dryRun When true, log what would be created without writing
     * @param list<int>|null $pageIds Optional filter to restrict to specific pages
     * @return int<0, max> Number of pages that failed to migrate
     */
    public function run(
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun = false,
        ?array $pageIds = null,
    ): int {
        $eligiblePages = $this->reader->getEligiblePages('draft', $pageIds);
        $failures = 0;

        foreach ($eligiblePages as $pageInfo) {
            $pageId = $pageInfo['pageId'];
            $areaId = $pageInfo['areaId'];
            $pageClassName = $pageInfo['pageClassName'];

            try {
                $this->migratePage($pageId, $areaId, $pageClassName, $defaultViewport, $zone, $viewportKeyMap, $dryRun);
            } catch (\Throwable $exception) {
                $failures++;
                $this->logger->error('Migration failed for page {pageId}: {message}', [
                    'pageId' => $pageId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if (!$dryRun) {
            $this->migrateDisabledGridPages();
        }

        return $failures;
    }

    /**
     * Migrate a single page's legacy elements to the new grid hierarchy.
     *
     * @param class-string $pageClassName Concrete page class (e.g. 'Page', not 'SiteTree')
     * @param array<string, string> $viewportKeyMap
     */
    private function migratePage(
        int $pageId,
        int $areaId,
        string $pageClassName,
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun,
    ): void {
        // Step 1: Idempotency — skip if Sections already exist for this page + zone
        if ($this->hasExistingSections($pageId, $pageClassName, $zone)) {
            $this->logger->info('Page {pageId} already migrated for zone "{zone}", skipping.', [
                'pageId' => $pageId,
                'zone' => $zone,
            ]);
            return;
        }

        // Step 2: Read DRAFT legacy elements
        $draftElements = $this->reader->getElementsForArea($areaId, 'draft');

        // Step 3-4: Build hierarchy via strategy (works on draft elements)
        $sections = [];
        if ($draftElements !== []) {
            $sections = $this->strategy->buildHierarchy($draftElements, $pageId, $zone);
        }

        // Step 5: Dry-run — log and return
        if ($dryRun) {
            $this->logDryRun($pageId, $sections);
            return;
        }

        // No draft elements and no live elements means nothing to migrate
        if ($sections === []) {
            $liveElements = $this->reader->getElementsForArea($areaId, 'live');
            if ($liveElements === []) {
                $this->logger->info('Page {pageId} has no elements to migrate.', ['pageId' => $pageId]);
                return;
            }
        }

        // Steps 6-8: Transaction-wrapped write
        $conn = DB::get_conn();
        if ($conn === null) {
            throw new \RuntimeException('No database connection available for migration.');
        }
        $conn->transactionStart();

        try {
            // Suppress auto-scaffolding process-wide during migration to prevent
            // Section/Row onAfterWrite hooks from creating duplicate child records.
            // Restored in the finally block below.
            Section::config()->set('auto_scaffold', false);
            Row::config()->set('auto_scaffold', false);

            try {
                // Step 6: Write draft hierarchy
                /** @var array<int, int> $oldToNewElementId */
                $oldToNewElementId = [];
                /** @var array<int, int> $oldToNewColumnId */
                $oldToNewColumnId = [];

                Versioned::withVersionedMode(function () use (
                    $pageId,
                    $pageClassName,
                    $zone,
                    $sections,
                    &$oldToNewElementId,
                    &$oldToNewColumnId,
                ): void {
                    Versioned::set_stage(Versioned::DRAFT);
                    $this->writeDraftHierarchy($pageId, $pageClassName, $zone, $sections, $oldToNewElementId, $oldToNewColumnId);
                });

                // Step 7: Publish draft records to live for elements that also existed on live.
                // Stage is set to DRAFT because we read the draft-created records
                // and then call writeToStage(LIVE) to copy them to _Live tables.
                // overwriteLiveContent() then corrects _Live with live-specific values.
                $liveElements = $this->reader->getElementsForArea($areaId, 'live');
                if ($liveElements !== []) {
                    Versioned::withVersionedMode(function () use (
                        $pageId,
                        $pageClassName,
                        $zone,
                        $liveElements,
                        $oldToNewElementId,
                        $oldToNewColumnId,
                        $defaultViewport,
                        $viewportKeyMap,
                    ): void {
                        Versioned::set_stage(Versioned::DRAFT);
                        $this->publishToLive(
                            $pageId,
                            $pageClassName,
                            $zone,
                            $liveElements,
                            $oldToNewElementId,
                            $oldToNewColumnId,
                            $defaultViewport,
                            $viewportKeyMap,
                        );
                    });
                }

                $conn->transactionEnd();

                $hasLiveContent = $liveElements !== [];
                $this->setUseGridOnPage($pageId, true, $hasLiveContent);

                $this->logger->info('Successfully migrated page {pageId}.', ['pageId' => $pageId]);
            } finally {
                // Step 9: Restore auto-scaffolding
                Section::config()->set('auto_scaffold', true);
                Row::config()->set('auto_scaffold', true);
            }
        } catch (\Throwable $exception) {
            $conn->transactionRollback();
            throw $exception;
        }
    }

    /**
     * Check whether Sections already exist for a page + zone on draft stage.
     *
     * @param class-string $pageClassName
     */
    private function hasExistingSections(int $pageId, string $pageClassName, string $zone): bool
    {
        $count = Versioned::withVersionedMode(function () use ($pageId, $pageClassName, $zone): int {
            Versioned::set_stage(Versioned::DRAFT);

            return Section::get()->filter([
                'ParentID' => $pageId,
                'ParentClass' => $pageClassName,
                'Zone' => $zone,
            ])->count();
        });

        return $count > 0;
    }

    /**
     * Write the full Section → Row → Column → Element hierarchy to DRAFT.
     *
     * @param class-string $pageClassName
     * @param list<MigrationSection> $sections
     * @param array<int, int> $oldToNewElementId Populated by reference
     * @param array<int, int> $oldToNewColumnId Populated by reference
     */
    private function writeDraftHierarchy(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $sections,
        array &$oldToNewElementId,
        array &$oldToNewColumnId,
    ): void {
        foreach ($sections as $migrationSection) {
            $section = $this->createSection($migrationSection, $pageId, $pageClassName, $zone);

            foreach ($migrationSection->rows as $migrationRow) {
                $row = $this->createRow($migrationRow, (int) $section->ID);

                foreach ($migrationRow->columns as $migrationColumn) {
                    $column = $this->createColumn($migrationColumn, (int) $row->ID);
                    $columnId = (int) $column->ID;

                    $elementSort = 1;
                    foreach ($migrationColumn->elements as $legacyElement) {
                        $newElement = $this->buildContentElement($legacyElement, $columnId, $elementSort);
                        $newElement->write();

                        $oldToNewElementId[$legacyElement->id] = (int) $newElement->ID;
                        $oldToNewColumnId[$legacyElement->id] = $columnId;
                        $elementSort++;
                    }
                }
            }
        }
    }

    /**
     * @param class-string $pageClassName
     */
    private function createSection(MigrationSection $migration, int $pageId, string $pageClassName, string $zone): Section
    {
        $section = Section::create();
        $section->Title = '';
        $section->Zone = $zone;
        $section->ExtraClass = $migration->extraClass;
        $section->Sort = $migration->sort;
        $section->ParentID = $pageId;
        $section->ParentClass = $pageClassName;
        $section->write();

        return $section;
    }

    private function createRow(MigrationRow $migration, int $sectionId): Row
    {
        $row = Row::create();
        $row->Title = $migration->title;
        $row->ExtraClass = $migration->extraClass;
        $row->Sort = $migration->sort;
        $row->ParentID = $sectionId;
        $row->ParentClass = Section::class;
        $row->write();

        return $row;
    }

    private function createColumn(MigrationColumn $migration, int $rowId): Column
    {
        $column = Column::create();
        $column->Title = '';
        $column->Sort = $migration->sort;
        $column->ParentID = $rowId;
        $column->ParentClass = Row::class;
        $column->setGridSettings($migration->gridSettings);
        $column->write();

        return $column;
    }

    /**
     * Build a GridElement from legacy data without writing.
     *
     * Shared by both draft creation (writeDraftHierarchy) and live-only
     * creation (createLiveOnlyElement) to avoid duplicating the field
     * mapping, class name resolution, and extension hook logic.
     */
    private function buildContentElement(LegacyElement $legacyElement, int $columnId, int $sort): GridElement
    {
        $newClassName = $this->mapper->resolveClassName($legacyElement->className);
        $oldClassName = $legacyElement->className;
        $this->extend('updateClassNameMapping', $newClassName, $oldClassName);

        if (!\is_a($newClassName, GridElement::class, true)) {
            throw new \RuntimeException(\sprintf(
                'Resolved class "%s" (from legacy "%s") does not extend %s.',
                $newClassName,
                $oldClassName,
                GridElement::class,
            ));
        }

        /** @var GridElement $newElement */
        $newElement = $newClassName::create();

        $newElement->Title = $legacyElement->title;
        $newElement->ShowTitle = $legacyElement->showTitle;
        /** @var 'h1'|'h2'|'h3'|'h4'|'h5'|'h6' $titleTag */
        $titleTag = $legacyElement->titleTag !== '' ? $legacyElement->titleTag : 'h2';
        $newElement->TitleTag = $titleTag;
        $newElement->TitleClass = $legacyElement->titleClass;
        $newElement->Sort = $sort;
        $newElement->ExtraClass = $legacyElement->extraClass;
        $newElement->ParentID = $columnId;
        $newElement->ParentClass = Column::class;

        if ($legacyElement->mediaData !== null && $newElement instanceof ContentElement) {
            $html = $legacyElement->mediaData->fields['HTML'] ?? null;
            if (\is_string($html)) {
                $newElement->HTML = $html;
            }

            foreach ($this->mapper->mapMediaFields($legacyElement->mediaData)->toArray() as $field => $value) {
                $newElement->__set($field, $value);
            }
        }

        $this->extend('updateElementFieldMapping', $newElement, $legacyElement);
        /** @var GridElement $newElement */

        return $newElement;
    }

    /**
     * Publish draft containers and content elements to LIVE stage.
     *
     * For elements that exist on both draft and live, the same new records
     * (created in draft) are published to live. For live-only elements
     * (no draft counterpart), new records are created on both stages.
     *
     * @param class-string $pageClassName
     * @param list<LegacyElement> $liveElements
     * @param array<int, int> $oldToNewElementId
     * @param array<int, int> $oldToNewColumnId
     * @param array<string, string> $viewportKeyMap
     */
    private function publishToLive(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $liveElements,
        array $oldToNewElementId,
        array $oldToNewColumnId,
        string $defaultViewport,
        array $viewportKeyMap,
    ): void {
        // Track which containers we've already published
        /** @var array<int, bool> $publishedContainers */
        $publishedContainers = [];

        foreach ($liveElements as $liveElement) {
            if ($liveElement->isRow) {
                continue;
            }

            $oldId = $liveElement->id;

            if (\array_key_exists($oldId, $oldToNewElementId)) {
                // Element exists on both stages — publish existing draft records to live
                $newElementId = $oldToNewElementId[$oldId];
                $newColumnId = $oldToNewColumnId[$oldId];

                $this->publishContainerChain($newColumnId, $publishedContainers);

                $element = GridElement::get()->byID($newElementId);
                if ($element instanceof GridElement) {
                    // Publish draft structure to live (creates _Live rows with
                    // correct ID, ParentID, ParentClass, ClassName).
                    $element->writeToStage(Versioned::LIVE);

                    // Overwrite live content fields with live-specific values,
                    // since writeToStage copied draft content to live.
                    $this->overwriteLiveContent($newElementId, $liveElement);
                }
            } else {
                // Live-only element — create new records on both draft and live
                $this->createLiveOnlyElement(
                    $liveElement,
                    $pageId,
                    $pageClassName,
                    $zone,
                    $publishedContainers,
                    $defaultViewport,
                    $viewportKeyMap,
                );
            }
        }
    }

    /**
     * Publish a Column and its parent Row and Section to live (if not already done).
     *
     * @param array<int, bool> $publishedContainers Modified by reference
     */
    private function publishContainerChain(int $columnId, array &$publishedContainers): void
    {
        if (\array_key_exists($columnId, $publishedContainers)) {
            return;
        }

        $column = Column::get()->byID($columnId);
        if (!$column instanceof Column) {
            return;
        }

        $rowId = (int) $column->ParentID;
        $row = Row::get()->byID($rowId);
        if ($row instanceof Row) {
            $sectionId = (int) $row->ParentID;
            $section = Section::get()->byID($sectionId);
            if ($section instanceof Section && !isset($publishedContainers[$sectionId])) {
                $section->writeToStage(Versioned::LIVE);
                $publishedContainers[$sectionId] = true;
            }
            if (!isset($publishedContainers[$rowId])) {
                $row->writeToStage(Versioned::LIVE);
                $publishedContainers[$rowId] = true;
            }
        }

        $column->writeToStage(Versioned::LIVE);
        $publishedContainers[$columnId] = true;
    }

    /**
     * Overwrite live content fields with live-specific values.
     *
     * After writeToStage(LIVE) copies draft content to the _Live tables,
     * this method corrects the live rows with the actual live element data.
     * This prevents draft-only changes from leaking onto the live site.
     */
    private function overwriteLiveContent(int $newElementId, LegacyElement $liveElement): void
    {
        // Base fields on GridElement_Live
        /** @var 'h1'|'h2'|'h3'|'h4'|'h5'|'h6' $titleTag */
        $titleTag = $liveElement->titleTag !== '' ? $liveElement->titleTag : 'h2';

        DB::prepared_query(
            'UPDATE "GridElement_Live" SET
                "Title" = ?,
                "ShowTitle" = ?,
                "TitleTag" = ?,
                "TitleClass" = ?,
                "Sort" = ?,
                "ExtraClass" = ?
            WHERE "ID" = ?',
            [
                $liveElement->title,
                $liveElement->showTitle ? 1 : 0,
                $titleTag,
                $liveElement->titleClass,
                $liveElement->sort,
                $liveElement->extraClass,
                $newElementId,
            ],
        );

        // Subclass fields on ContentElement_Live (HTML + media)
        if ($liveElement->mediaData === null) {
            return;
        }

        /** @var array<string, mixed> $liveFields */
        $liveFields = ['HTML' => ''];

        $html = $liveElement->mediaData->fields['HTML'] ?? null;
        if (\is_string($html)) {
            $liveFields['HTML'] = $html;
        }

        $mappedMedia = $this->mapper->mapMediaFields($liveElement->mediaData);
        foreach ($mappedMedia->toArray() as $field => $value) {
            $liveFields[$field] = $value;
        }

        // Field names are compile-time constants from MappedMediaFields::toArray(), not user input.
        $setClauses = [];
        $params = [];
        foreach ($liveFields as $field => $value) {
            $setClauses[] = \sprintf('"%s" = ?', $field);
            $params[] = $value;
        }
        $params[] = $newElementId;

        DB::prepared_query(
            \sprintf(
                'UPDATE "ContentElement_Live" SET %s WHERE "ID" = ?',
                \implode(', ', $setClauses),
            ),
            $params,
        );
    }

    /**
     * Create a live-only element with its container chain on both stages.
     *
     * Live-only elements need records on BOTH draft and live to maintain
     * Versioned integrity. We create a minimal Section→Row→Column chain
     * if one doesn't already exist.
     *
     * @param class-string $pageClassName
     * @param array<int, bool> $publishedContainers
     * @param array<string, string> $viewportKeyMap
     */
    private function createLiveOnlyElement(
        LegacyElement $liveElement,
        int $pageId,
        string $pageClassName,
        string $zone,
        array &$publishedContainers,
        string $defaultViewport,
        array $viewportKeyMap,
    ): void {
        // Build grid settings for the live-only element
        $gridSettings = $this->mapper->mapGridSettings($liveElement, $defaultViewport, $viewportKeyMap);

        // Create Section → Row → Column on draft first
        $section = Section::create();
        $section->Title = '';
        $section->Zone = $zone;
        $section->Sort = $this->getNextSectionSort($pageId, $pageClassName, $zone);
        $section->ParentID = $pageId;
        $section->ParentClass = $pageClassName;
        $section->write();

        $row = Row::create();
        $row->Title = '';
        $row->Sort = 1;
        $row->ParentID = (int) $section->ID;
        $row->ParentClass = Section::class;
        $row->write();

        $column = Column::create();
        $column->Title = '';
        $column->Sort = 1;
        $column->ParentID = (int) $row->ID;
        $column->ParentClass = Row::class;
        $column->setGridSettings($gridSettings);
        $column->write();

        // Create content element on draft using shared builder
        $newElement = $this->buildContentElement($liveElement, (int) $column->ID, 1);
        $newElement->write();

        // Publish all to live
        $section->writeToStage(Versioned::LIVE);
        $row->writeToStage(Versioned::LIVE);
        $column->writeToStage(Versioned::LIVE);
        $newElement->writeToStage(Versioned::LIVE);

        $publishedContainers[(int) $section->ID] = true;
        $publishedContainers[(int) $row->ID] = true;
        $publishedContainers[(int) $column->ID] = true;
    }

    /**
     * Get the next available Sort value for Sections under a page + zone.
     *
     * @param class-string $pageClassName
     */
    private function getNextSectionSort(int $pageId, string $pageClassName, string $zone): int
    {
        $max = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => $pageClassName,
            'Zone' => $zone,
        ])->max('Sort');

        return (\is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * Set the UseGrid flag on a page's SiteTree record(s).
     *
     * Uses raw SQL for consistency with the migration's existing approach
     * to live-stage table updates.
     */
    private function setUseGridOnPage(int $pageId, bool $enabled, bool $includeLive = false): void
    {
        $value = $enabled ? 1 : 0;

        DB::prepared_query(
            'UPDATE "SiteTree" SET "UseGrid" = ? WHERE "ID" = ?',
            [$value, $pageId],
        );

        if ($includeLive) {
            DB::prepared_query(
                'UPDATE "SiteTree_Live" SET "UseGrid" = ? WHERE "ID" = ?',
                [$value, $pageId],
            );
        }
    }

    /**
     * Preserve UseGrid = 0 for pages that had UseElementalGrid disabled.
     *
     * These pages are excluded from content migration (no grid content to move)
     * but their toggle state must be carried forward so the Content editor
     * remains active after migration.
     */
    private function migrateDisabledGridPages(): void
    {
        $draftPages = $this->reader->getPagesWithGridDisabled('draft');
        foreach ($draftPages as $pageInfo) {
            $this->setUseGridOnPage($pageInfo['pageId'], false);
        }

        $livePages = $this->reader->getPagesWithGridDisabled('live');
        foreach ($livePages as $pageInfo) {
            DB::prepared_query(
                'UPDATE "SiteTree_Live" SET "UseGrid" = 0 WHERE "ID" = ?',
                [$pageInfo['pageId']],
            );
        }

        $totalPages = \count($draftPages) + \count($livePages);
        if ($totalPages > 0) {
            $this->logger->info('Set UseGrid = 0 for {draftCount} draft and {liveCount} live page(s) with grid disabled.', [
                'draftCount' => \count($draftPages),
                'liveCount' => \count($livePages),
            ]);
        }
    }

    /**
     * Log what a dry-run would create without writing any records.
     *
     * @param list<MigrationSection> $sections
     */
    private function logDryRun(int $pageId, array $sections): void
    {
        if ($sections === []) {
            $this->logger->info('[DRY RUN] Page {pageId}: no elements to migrate.', ['pageId' => $pageId]);
            return;
        }

        $sectionCount = \count($sections);
        $rowCount = 0;
        $columnCount = 0;

        foreach ($sections as $section) {
            $rowCount += \count($section->rows);
            foreach ($section->rows as $row) {
                $columnCount += \count($row->columns);
            }
        }

        $this->logger->info(
            '[DRY RUN] Page {pageId}: would create {sections} section(s), {rows} row(s), {columns} column(s).',
            [
                'pageId' => $pageId,
                'sections' => $sectionCount,
                'rows' => $rowCount,
                'columns' => $columnCount,
            ],
        );
    }
}
