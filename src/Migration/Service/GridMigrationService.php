<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use Throwable;
use RuntimeException;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DataObject;
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
                $this->migratePage($pageId, $areaId, $pageClassName, $zone, $dryRun);
            } catch (Throwable $exception) {
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
     * Grid-settings / viewport mapping is handled entirely by the injected
     * {@see RowMappingStrategy} (constructed with the default viewport and
     * viewport key map), so those values are not threaded through here.
     *
     * @param class-string $pageClassName Concrete page class (e.g. 'Page', not 'SiteTree')
     */
    private function migratePage(
        int $pageId,
        int $areaId,
        string $pageClassName,
        string $zone,
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
            throw new RuntimeException('No database connection available for migration.');
        }
        $conn->transactionStart();

        // Capture the current auto_scaffold values so the finally block can
        // restore the project's actual configuration rather than a hardcoded
        // default. A project may legitimately set auto_scaffold: false.
        $sectionAutoScaffold = (bool) Section::config()->get('auto_scaffold');
        $rowAutoScaffold = (bool) Row::config()->get('auto_scaffold');

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
                /** @var array<int, int> $oldToDraftSort */
                $oldToDraftSort = [];

                Versioned::withVersionedMode(function () use (
                    $pageId,
                    $pageClassName,
                    $zone,
                    $sections,
                    &$oldToNewElementId,
                    &$oldToNewColumnId,
                    &$oldToDraftSort,
                ): void {
                    Versioned::set_stage(Versioned::DRAFT);
                    $this->writeDraftHierarchy($pageId, $pageClassName, $zone, $sections, $oldToNewElementId, $oldToNewColumnId, $oldToDraftSort);
                });

                // Step 7: Publish draft records to live for elements that also existed on live.
                // Stage is set to DRAFT because we read the draft-created records
                // and then call writeToStage(LIVE) to copy them to _Live tables.
                // overwriteLiveContent() then corrects _Live with live-specific values.
                $liveElements = $this->reader->getElementsForArea($areaId, 'live');
                if ($liveElements !== []) {
                    // Build the set of legacy DRAFT element IDs (rows AND content
                    // elements). A live element is "live-only" only when its legacy
                    // ID has no draft counterpart in this set; elements present on
                    // both legacy stages are "shared" and must be published from the
                    // draft records, never re-created as a live-only hierarchy.
                    //
                    // $oldToNewElementId cannot be used for this test: it only holds
                    // content elements (row delimiters are grouping boundaries and are
                    // never written as records), so a shared row would be misread as
                    // live-only and spawn a spurious section.
                    /** @var array<int, true> $draftLegacyIds */
                    $draftLegacyIds = [];
                    foreach ($draftElements as $draftElement) {
                        $draftLegacyIds[$draftElement->id] = true;
                    }

                    Versioned::withVersionedMode(function () use (
                        $pageId,
                        $pageClassName,
                        $zone,
                        $liveElements,
                        $draftLegacyIds,
                        $oldToNewElementId,
                        $oldToNewColumnId,
                        $oldToDraftSort,
                    ): void {
                        Versioned::set_stage(Versioned::DRAFT);
                        $this->publishToLive(
                            $pageId,
                            $pageClassName,
                            $zone,
                            $liveElements,
                            $draftLegacyIds,
                            $oldToNewElementId,
                            $oldToNewColumnId,
                            $oldToDraftSort,
                        );
                    });
                }

                $hasLiveContent = $liveElements !== [];
                $this->setUseGridOnPage($pageId, true, includeLive: $hasLiveContent);

                $conn->transactionEnd();

                $this->logger->info('Successfully migrated page {pageId}.', ['pageId' => $pageId]);
            } finally {
                // Step 9: Restore auto-scaffolding to the previously captured values.
                Section::config()->set('auto_scaffold', $sectionAutoScaffold);
                Row::config()->set('auto_scaffold', $rowAutoScaffold);
            }
        } catch (Throwable $exception) {
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
     * @param array<int, int> $oldToDraftSort Populated by reference: old element ID → column-local Sort assigned on draft
     */
    private function writeDraftHierarchy(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $sections,
        array &$oldToNewElementId,
        array &$oldToNewColumnId,
        array &$oldToDraftSort,
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
                        $oldToDraftSort[$legacyElement->id] = $elementSort;
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
     * creation (createLiveOnlyHierarchy) to avoid duplicating the field
     * mapping, class name resolution, and extension hook logic.
     */
    private function buildContentElement(LegacyElement $legacyElement, int $columnId, int $sort): GridElement
    {
        $newClassName = $this->mapper->resolveClassName($legacyElement->className);
        $oldClassName = $legacyElement->className;
        $this->extend('updateClassNameMapping', $newClassName, $oldClassName);

        if (!\is_a($newClassName, GridElement::class, true)) {
            throw new RuntimeException(\sprintf(
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
     * @param array<int, true> $draftLegacyIds Set of legacy element IDs present on the draft area (rows + content)
     * @param array<int, int> $oldToNewElementId
     * @param array<int, int> $oldToNewColumnId
     * @param array<int, int> $oldToDraftSort Old element ID → column-local Sort assigned on draft
     */
    private function publishToLive(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $liveElements,
        array $draftLegacyIds,
        array $oldToNewElementId,
        array $oldToNewColumnId,
        array $oldToDraftSort,
    ): void {
        // Track which containers we've already published
        /** @var array<int, bool> $publishedContainers */
        $publishedContainers = [];

        // Live-only elements are collected and processed as a single grouped
        // hierarchy after the loop, mirroring the draft path. Row delimiters are
        // retained in the collection so ElementGrouper can honour row boundaries.
        /** @var list<LegacyElement> $liveOnlyElements */
        $liveOnlyElements = [];

        foreach ($liveElements as $liveElement) {
            $oldId = $liveElement->id;
            $existsOnDraft = \array_key_exists($oldId, $draftLegacyIds);

            if (!$existsOnDraft) {
                // Live-only element (or live-only row delimiter) — defer to the
                // grouped hierarchy build below. Rows carry no draft counterpart
                // and act purely as grouping boundaries.
                $liveOnlyElements[] = $liveElement;
                continue;
            }

            // Row delimiters that exist on both stages are not written as
            // records on either stage — skip publishing them. (Shared rows live
            // in $draftLegacyIds but never in $oldToNewElementId, so they reach
            // this branch rather than the live-only collection above.)
            if ($liveElement->isRow) {
                continue;
            }

            // Element exists on both stages — publish existing draft records to live.
            // Every non-row draft element is written by writeDraftHierarchy, so a
            // shared element is guaranteed to have a mapped record here. A missing
            // key can only mean the injected RowMappingStrategy dropped a content
            // element it was handed; fail loudly (rolling back the page) rather than
            // silently skipping live content via an undefined-key warning and a
            // byID(null) no-op.
            if (!\array_key_exists($oldId, $oldToNewElementId)) {
                throw new RuntimeException(\sprintf(
                    'Shared legacy element %d has no migrated draft record; '
                    . 'the configured row mapping strategy dropped a content element.',
                    $oldId,
                ));
            }

            $newElementId = $oldToNewElementId[$oldId];
            $newColumnId = $oldToNewColumnId[$oldId];

            $this->publishContainerChain($newColumnId, $publishedContainers);

            $element = GridElement::get()->byID($newElementId);
            if ($element instanceof GridElement) {
                // Publish draft structure to live (creates _Live rows with
                // correct ID, ParentID, ParentClass, ClassName).
                $element->writeToStage(Versioned::LIVE);

                // Overwrite live content fields with live-specific values,
                // since writeToStage copied draft content to live. The Sort is
                // the column-local value assigned on draft (not the legacy
                // area-wide value) so both stages order the column identically.
                $this->overwriteLiveContent($newElementId, $liveElement, $oldToDraftSort[$oldId]);
            }
        }

        if ($liveOnlyElements !== []) {
            $this->createLiveOnlyHierarchy(
                $liveOnlyElements,
                $pageId,
                $pageClassName,
                $zone,
                $publishedContainers,
            );
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
     *
     * The Sort written here is the column-local value assigned to the element
     * on draft, NOT the legacy area-wide {@see LegacyElement::$sort}. Using the
     * draft Sort keeps both stages ordering the column's children identically;
     * writing the raw legacy Sort would diverge the two stages.
     *
     * @param int $draftSort Column-local Sort assigned to this element on draft
     */
    private function overwriteLiveContent(int $newElementId, LegacyElement $liveElement, int $draftSort): void
    {
        $schema = DataObject::getSchema();

        // Base fields on the GridElement live table
        /** @var 'h1'|'h2'|'h3'|'h4'|'h5'|'h6' $titleTag */
        $titleTag = $liveElement->titleTag !== '' ? $liveElement->titleTag : 'h2';

        DB::prepared_query(
            \sprintf(
                'UPDATE "%s_Live" SET
                    "Title" = ?,
                    "ShowTitle" = ?,
                    "TitleTag" = ?,
                    "TitleClass" = ?,
                    "Sort" = ?,
                    "ExtraClass" = ?
                WHERE "ID" = ?',
                $schema->tableName(GridElement::class),
            ),
            [
                $liveElement->title,
                $liveElement->showTitle ? 1 : 0,
                $titleTag,
                $liveElement->titleClass,
                $draftSort,
                $liveElement->extraClass,
                $newElementId,
            ],
        );

        // Subclass fields on the ContentElement live table (HTML + media)
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
                'UPDATE "%s_Live" SET %s WHERE "ID" = ?',
                $schema->tableName(ContentElement::class),
                \implode(', ', $setClauses),
            ),
            $params,
        );
    }

    /**
     * Create the live-only content as a grouped Section → Row → Column → Element
     * hierarchy on BOTH stages.
     *
     * Live-only elements (present on the legacy live area with no draft
     * counterpart) still require a draft row: SilverStripe's Versioned stores the
     * canonical record in the base (draft) table, and a freshly created object
     * written through {@see Versioned::writeToStage()} with stage LIVE inserts the
     * base-table row regardless (see Versioned::augmentWriteStaged — the pre-insert
     * DELETE is a no-op for a brand-new record). Both stages are therefore written
     * explicitly so the records are well-formed (`write()` for draft, then
     * `writeToStage(LIVE)` to publish).
     *
     * IMPORTANT: only genuinely live-only legacy elements reach this method. The
     * caller classifies an element as live-only solely when its legacy ID has no
     * counterpart on the legacy *draft* area (see $draftLegacyIds in publishToLive),
     * so shared elements — including shared row delimiters — are never re-created
     * here. That guard is what keeps draft/live divergence intact: a shared element
     * is published from its existing draft record, not duplicated as a new section.
     *
     * To match the layout the draft path produces, the elements are run through the
     * same configured strategy (ElementGrouper + grid-settings grouping + row
     * boundaries) rather than given a dedicated chain each. Records are written
     * top-down (Section → Row → Column → Element) so each child can reference its
     * just-written parent ID. The resulting Sections are sorted after any Sections
     * already created on this page + zone from the draft path; both stages share one
     * Sort sequence, so the draft max-Sort yields the correct live append offset.
     *
     * Known limitation: the live-only collection is the original live order with the
     * shared elements removed, so a shared element that sat between two live-only
     * elements with identical grid settings no longer separates them. The grouping
     * pass may then collapse those two into a single column they did not share on the
     * legacy live stage. This is an accepted layout nuance of a one-shot migration —
     * no content is lost or reordered; only the column grouping of adjacent
     * same-settings live-only elements can differ from the legacy layout.
     *
     * @param list<LegacyElement> $liveOnlyElements Live-only elements (may include row delimiters), in original order
     * @param class-string $pageClassName
     * @param array<int, bool> $publishedContainers Modified by reference
     */
    private function createLiveOnlyHierarchy(
        array $liveOnlyElements,
        int $pageId,
        string $pageClassName,
        string $zone,
        array &$publishedContainers,
    ): void {
        $sections = $this->strategy->buildHierarchy($liveOnlyElements, $pageId, $zone);
        if ($sections === []) {
            return;
        }

        // Offset the strategy's 1-based section sort past any sections the draft
        // path already wrote for this page + zone, so live-only content appends
        // rather than colliding with existing sort values.
        $sortOffset = $this->getNextSectionSort($pageId, $pageClassName, $zone) - 1;

        foreach ($sections as $migrationSection) {
            $sectionSort = $migrationSection->sort + $sortOffset;
            $section = $this->createSectionWithSort($migrationSection, $pageId, $pageClassName, $zone, $sectionSort);
            $sectionId = (int) $section->ID;

            foreach ($migrationSection->rows as $migrationRow) {
                $row = $this->createRow($migrationRow, $sectionId);
                $rowId = (int) $row->ID;

                foreach ($migrationRow->columns as $migrationColumn) {
                    $column = $this->createColumn($migrationColumn, $rowId);
                    $columnId = (int) $column->ID;

                    $elementSort = 1;
                    foreach ($migrationColumn->elements as $legacyElement) {
                        $newElement = $this->buildContentElement($legacyElement, $columnId, $elementSort);
                        $newElement->write();
                        $newElement->writeToStage(Versioned::LIVE);
                        $elementSort++;
                    }

                    $column->writeToStage(Versioned::LIVE);
                    $publishedContainers[$columnId] = true;
                }

                $row->writeToStage(Versioned::LIVE);
                $publishedContainers[$rowId] = true;
            }

            $section->writeToStage(Versioned::LIVE);
            $publishedContainers[$sectionId] = true;
        }
    }

    /**
     * Create a Section with an explicit Sort value (overriding the DTO's sort).
     *
     * @param class-string $pageClassName
     */
    private function createSectionWithSort(
        MigrationSection $migration,
        int $pageId,
        string $pageClassName,
        string $zone,
        int $sort,
    ): Section {
        $section = Section::create();
        $section->Title = '';
        $section->Zone = $zone;
        $section->ExtraClass = $migration->extraClass;
        $section->Sort = $sort;
        $section->ParentID = $pageId;
        $section->ParentClass = $pageClassName;
        $section->write();

        return $section;
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
     * Set the UseGrid flag on a page record.
     *
     * Uses raw SQL for consistency with the migration's existing approach
     * to live-stage table updates. The table is resolved dynamically because
     * the UseGrid column lives on whichever page class has GridPageExtension
     * applied (e.g. Page, not necessarily SiteTree).
     *
     * @param bool $includeDraft Update draft table
     * @param bool $includeLive Update live (_Live) table
     */
    private function setUseGridOnPage(int $pageId, bool $enabled, bool $includeDraft = true, bool $includeLive = false): void
    {
        $table = $this->resolveUseGridTable();
        if ($table === null) {
            return;
        }

        $value = $enabled ? 1 : 0;

        if ($includeDraft) {
            DB::prepared_query(
                \sprintf('UPDATE "%s" SET "UseGrid" = ? WHERE "ID" = ?', $table),
                [$value, $pageId],
            );
        }

        if ($includeLive) {
            DB::prepared_query(
                \sprintf('UPDATE "%s_Live" SET "UseGrid" = ? WHERE "ID" = ?', $table),
                [$value, $pageId],
            );
        }
    }

    /**
     * Find which table in the SiteTree hierarchy stores the UseGrid column.
     *
     * GridPageExtension can be applied to any page class (Page, a custom
     * subclass, etc.), so the table is not known at compile time.
     *
     * @return non-empty-string|null Table name, or null if no page class has the column
     */
    private function resolveUseGridTable(): ?string
    {
        $schema = DataObject::getSchema();

        foreach (ClassInfo::subclassesFor(SiteTree::class, true) as $class) {
            $fieldClass = $schema->classForField($class, 'UseGrid');
            if ($fieldClass !== null) {
                /** @var non-empty-string */
                return $schema->tableName($fieldClass);
            }
        }

        return null;
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
            $this->setUseGridOnPage($pageInfo['pageId'], false, includeDraft: false, includeLive: true);
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
