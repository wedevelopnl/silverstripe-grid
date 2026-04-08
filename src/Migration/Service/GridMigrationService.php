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
     */
    public function run(
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun = false,
        ?array $pageIds = null,
    ): void {
        $eligiblePages = $this->reader->getEligiblePages('draft', $pageIds);

        foreach ($eligiblePages as $pageInfo) {
            $pageId = $pageInfo['pageId'];
            $areaId = $pageInfo['areaId'];

            try {
                $this->migratePage($pageId, $areaId, $defaultViewport, $zone, $viewportKeyMap, $dryRun);
            } catch (\Throwable $exception) {
                $this->logger->error('Migration failed for page {pageId}: {message}', [
                    'pageId' => $pageId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Migrate a single page's legacy elements to the new grid hierarchy.
     *
     * @param array<string, string> $viewportKeyMap
     */
    private function migratePage(
        int $pageId,
        int $areaId,
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun,
    ): void {
        // Step 1: Idempotency — skip if Sections already exist for this page + zone
        if ($this->hasExistingSections($pageId, $zone)) {
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
                    $zone,
                    $sections,
                    &$oldToNewElementId,
                    &$oldToNewColumnId,
                ): void {
                    Versioned::set_stage(Versioned::DRAFT);
                    $this->writeDraftHierarchy($pageId, $zone, $sections, $oldToNewElementId, $oldToNewColumnId);
                });

                // Step 7: Publish draft records to live for elements that also existed on live.
                // Stage is set to DRAFT because we read the draft-created records
                // and then call writeToStage(LIVE) to copy them to _Live tables.
                // overwriteLiveContent() then corrects _Live with live-specific values.
                $liveElements = $this->reader->getElementsForArea($areaId, 'live');
                if ($liveElements !== []) {
                    Versioned::withVersionedMode(function () use (
                        $pageId,
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
     */
    private function hasExistingSections(int $pageId, string $zone): bool
    {
        $count = Versioned::withVersionedMode(function () use ($pageId, $zone): int {
            Versioned::set_stage(Versioned::DRAFT);

            return Section::get()->filter([
                'ParentID' => $pageId,
                'ParentClass' => 'SilverStripe\\CMS\\Model\\SiteTree',
                'Zone' => $zone,
            ])->count();
        });

        return $count > 0;
    }

    /**
     * Write the full Section → Row → Column → Element hierarchy to DRAFT.
     *
     * @param list<MigrationSection> $sections
     * @param array<int, int> $oldToNewElementId Populated by reference
     * @param array<int, int> $oldToNewColumnId Populated by reference
     */
    private function writeDraftHierarchy(
        int $pageId,
        string $zone,
        array $sections,
        array &$oldToNewElementId,
        array &$oldToNewColumnId,
    ): void {
        foreach ($sections as $migrationSection) {
            $section = $this->createSection($migrationSection, $pageId, $zone);

            foreach ($migrationSection->rows as $migrationRow) {
                $row = $this->createRow($migrationRow, (int) $section->ID);

                foreach ($migrationRow->columns as $migrationColumn) {
                    $column = $this->createColumn($migrationColumn, (int) $row->ID);
                    $columnId = (int) $column->ID;

                    $element = $this->createContentElement($migrationColumn, $columnId);
                    $oldElementId = $migrationColumn->element->id;
                    $oldToNewElementId[$oldElementId] = (int) $element->ID;
                    $oldToNewColumnId[$oldElementId] = $columnId;
                }
            }
        }
    }

    private function createSection(MigrationSection $migration, int $pageId, string $zone): Section
    {
        $section = Section::create();
        $section->Title = '';
        $section->Zone = $zone;
        $section->ExtraClass = $migration->extraClass;
        $section->Sort = $migration->sort;
        $section->ParentID = $pageId;
        $section->ParentClass = 'SilverStripe\\CMS\\Model\\SiteTree';
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
     * Create a content element from a MigrationColumn's legacy element data.
     *
     * Resolves the ClassName, maps fields, and invokes extension hooks.
     */
    private function createContentElement(MigrationColumn $migration, int $columnId): GridElement
    {
        $newElement = $this->buildContentElement($migration->element, $columnId, $migration->element->sort);
        $newElement->write();

        return $newElement;
    }

    /**
     * Build a GridElement from legacy data without writing.
     *
     * Shared by both draft creation (createContentElement) and live-only
     * creation (createLiveOnlyElement) to avoid duplicating the field
     * mapping, class name resolution, and extension hook logic.
     */
    private function buildContentElement(LegacyElement $legacyElement, int $columnId, int $sort): GridElement
    {
        $newClassName = $this->mapper->resolveClassName($legacyElement->className);
        $oldClassName = $legacyElement->className;
        $this->extend('updateClassNameMapping', $newClassName, $oldClassName);

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

            $this->mapper->mapMediaFields($legacyElement->mediaData)->applyTo($newElement);
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
     * @param list<LegacyElement> $liveElements
     * @param array<int, int> $oldToNewElementId
     * @param array<int, int> $oldToNewColumnId
     * @param array<string, string> $viewportKeyMap
     */
    private function publishToLive(
        int $pageId,
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
     * @param array<int, bool> $publishedContainers
     * @param array<string, string> $viewportKeyMap
     */
    private function createLiveOnlyElement(
        LegacyElement $liveElement,
        int $pageId,
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
        $section->Sort = $this->getNextSectionSort($pageId, $zone);
        $section->ParentID = $pageId;
        $section->ParentClass = 'SilverStripe\\CMS\\Model\\SiteTree';
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
     */
    private function getNextSectionSort(int $pageId, string $zone): int
    {
        $max = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => 'SilverStripe\\CMS\\Model\\SiteTree',
            'Zone' => $zone,
        ])->max('Sort');

        return (\is_numeric($max) ? (int) $max : 0) + 1;
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
