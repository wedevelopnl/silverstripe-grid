<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use RuntimeException;
use Psr\Log\LoggerInterface;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\MigrationIdMap;

/**
 * Publishes migrated draft records to the LIVE stage: shared elements (published
 * from their draft record), live-only elements (created as a fresh hierarchy on
 * both stages), and live grid-settings reconciliation. Extracted verbatim from
 * GridMigrationService.
 *
 * Reuses {@see DraftHierarchyWriter} for the live-only hierarchy build (so the
 * `updateClassNameMapping` / `updateElementFieldMapping` extension hooks still
 * fire) and the {@see RowMappingStrategy} to group live-only elements the same
 * way the draft path does.
 */
final readonly class LivePublisher
{
    public function __construct(
        private FieldMapper $mapper,
        private DraftHierarchyWriter $draftWriter,
        private LoggerInterface $logger,
        private RowMappingStrategy $strategy,
    ) {}

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
     * @param string $defaultViewport Old viewport key used as default (e.g. 'MD')
     * @param array<string, string> $viewportKeyMap Old viewport key → new key
     */
    public function publishToLive(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $liveElements,
        array $draftLegacyIds,
        MigrationIdMap $idMap,
        string $defaultViewport,
        array $viewportKeyMap,
    ): void {
        // Live elements grouped by their target (new) Column ID, so the Column's
        // live GridSettings can be reconciled from the live Size after publish.
        /** @var array<int, list<LegacyElement>> $liveElementsByColumn */
        $liveElementsByColumn = [];

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
            // in $draftLegacyIds but never in the id map, so they reach this
            // branch rather than the live-only collection above.)
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
            if (!$idMap->hasElement($oldId)) {
                throw new RuntimeException(\sprintf(
                    'Shared legacy element %d has no migrated draft record; '
                    . 'the configured row mapping strategy dropped a content element.',
                    $oldId,
                ));
            }

            $newElementId = $idMap->newElementId($oldId);
            $newColumnId = $idMap->newColumnId($oldId);

            $liveElementsByColumn[$newColumnId][] = $liveElement;

            $this->publishContainerChain($newColumnId, $idMap);

            $element = GridElement::get()->byID($newElementId);
            if ($element instanceof GridElement) {
                // Publish draft structure to live (creates _Live rows with
                // correct ID, ParentID, ParentClass, ClassName).
                $element->writeToStage(Versioned::LIVE);

                // Overwrite live content fields with live-specific values,
                // since writeToStage copied draft content to live. The Sort is
                // the column-local value assigned on draft (not the legacy
                // area-wide value) so both stages order the column identically.
                $this->overwriteLiveContent($newElementId, $liveElement, $idMap->draftSort($oldId));
            }
        }

        // Reconcile each published Column's live GridSettings from the live
        // element Size. The Column width was derived from the draft Size and
        // copied to live by writeToStage(); without this pass a draft↔live Size
        // difference leaves the wrong width on the published front-end.
        $this->reconcileColumnLiveGridSettings($liveElementsByColumn, $defaultViewport, $viewportKeyMap);

        if ($liveOnlyElements !== []) {
            $this->logger->info(
                'Page {pageId}: {liveOnlyCount} live-only legacy element(s) found; '
                . 'adjacent same-settings elements may be grouped into shared columns — verify live layout.',
                [
                    'pageId' => $pageId,
                    'liveOnlyCount' => \count($liveOnlyElements),
                ],
            );
            $this->createLiveOnlyHierarchy(
                $liveOnlyElements,
                $pageId,
                $pageClassName,
                $zone,
                $idMap,
            );
        }
    }

    /**
     * Reconcile published Columns' live GridSettings from the live element Size.
     *
     * The Column's GridSettings is derived from the DRAFT element Size in
     * {@see DraftHierarchyWriter::createColumn()} and copied to the live table by
     * writeToStage(LIVE). For an element shared between draft and live whose
     * legacy Size differs, this would leave the live Column showing the
     * draft-derived width. Here the live settings are recomputed from the live
     * Size (using the same mapper and viewport configuration the draft grouping
     * used) and written to the live stage only when they differ from the
     * published (draft-derived) settings.
     *
     * The live row is updated with raw SQL (mirroring {@see overwriteLiveContent()})
     * rather than `setGridSettings()` + `writeToStage(LIVE)`: the ORM path,
     * starting from a draft-stage object, also rewrites the draft GridSettings,
     * which would corrupt the draft Column width. A targeted `_Live` UPDATE keeps
     * the draft untouched.
     *
     * @param array<int, list<LegacyElement>> $liveElementsByColumn New Column ID → live elements published into it
     * @param array<string, string> $viewportKeyMap Old viewport key → new key
     */
    private function reconcileColumnLiveGridSettings(
        array $liveElementsByColumn,
        string $defaultViewport,
        array $viewportKeyMap,
    ): void {
        if ($liveElementsByColumn === []) {
            return;
        }

        $columnLiveTable = DataObject::getSchema()->tableName(Column::class) . '_Live';

        foreach ($liveElementsByColumn as $columnId => $liveElements) {
            if ($liveElements === []) {
                continue;
            }

            $column = Column::get()->byID($columnId);
            if (!$column instanceof Column) {
                continue;
            }

            $liveSettings = $this->reconcileGridSettings($liveElements, $defaultViewport, $viewportKeyMap, $columnId);

            // The loaded column is on the draft stage and carries the
            // draft-derived settings (also just copied to live by writeToStage).
            // Skip the write when the live Size produces the same settings — the
            // common case, and keeps the pass idempotent.
            if ($liveSettings->equals($column->getGridSettings())) {
                continue;
            }

            // DBGridSettings stores the VO across four prefixed columns; Overrides
            // is NULL when empty, else JSON — matching DBGridSettings::applyGridSettings.
            $overrides = $liveSettings->overrides === []
                ? null
                : \json_encode($liveSettings->overrides, \JSON_THROW_ON_ERROR);

            DB::prepared_query(
                \sprintf(
                    'UPDATE "%s" SET
                        "GridSettingsDefaultWidth" = ?,
                        "GridSettingsDefaultOffset" = ?,
                        "GridSettingsDefaultVisible" = ?,
                        "GridSettingsOverrides" = ?
                    WHERE "ID" = ?',
                    $columnLiveTable,
                ),
                [
                    $liveSettings->default->width,
                    $liveSettings->default->offset,
                    $liveSettings->default->visible ? 1 : 0,
                    $overrides,
                    $columnId,
                ],
            );
        }
    }

    /**
     * Resolve a single GridSettings for a Column from its live elements.
     *
     * Draft grouping keys on the draft settings, so the live settings of the
     * elements in one Column can diverge. A Column can only carry one width, so
     * the first element's live settings win; any divergence is logged.
     *
     * @param non-empty-list<LegacyElement> $liveElements
     * @param array<string, string> $viewportKeyMap Old viewport key → new key
     */
    private function reconcileGridSettings(
        array $liveElements,
        string $defaultViewport,
        array $viewportKeyMap,
        int $columnId,
    ): GridSettings {
        $settings = null;

        foreach ($liveElements as $liveElement) {
            $candidate = $this->mapper->mapGridSettings($liveElement, $defaultViewport, $viewportKeyMap);

            if ($settings === null) {
                $settings = $candidate;
                continue;
            }

            if (!$candidate->equals($settings)) {
                $this->logger->warning(
                    'Live grid settings diverge within migrated column {columnId}; '
                    . 'using the first element\'s settings (a single column cannot express multiple widths).',
                    ['columnId' => $columnId],
                );
            }
        }

        /** @var GridSettings $settings Non-null: callers only pass non-empty element lists. */
        return $settings;
    }

    /**
     * Publish a Column and its parent Row and Section to live (if not already done).
     */
    private function publishContainerChain(int $columnId, MigrationIdMap $idMap): void
    {
        if ($idMap->isContainerPublished($columnId)) {
            return;
        }

        $column = Column::get()->byID($columnId);
        if (!$column instanceof Column) {
            return;
        }

        // $columnId resolved to a persisted Column, so it is a valid record ID.
        /** @var positive-int $columnId */

        $rowId = (int) $column->ParentID;
        $row = Row::get()->byID($rowId);
        if ($row instanceof Row) {
            // $rowId resolved to a persisted Row, so it is a valid record ID.
            /** @var positive-int $rowId */
            $sectionId = (int) $row->ParentID;
            $section = Section::get()->byID($sectionId);
            if ($section instanceof Section && !$idMap->isContainerPublished($sectionId)) {
                // $sectionId resolved to a persisted Section, so it is a valid record ID.
                /** @var positive-int $sectionId */
                $section->writeToStage(Versioned::LIVE);
                $idMap->markContainerPublished($sectionId);
            }
            if (!$idMap->isContainerPublished($rowId)) {
                $row->writeToStage(Versioned::LIVE);
                $idMap->markContainerPublished($rowId);
            }
        }

        $column->writeToStage(Versioned::LIVE);
        $idMap->markContainerPublished($columnId);
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
     */
    private function createLiveOnlyHierarchy(
        array $liveOnlyElements,
        int $pageId,
        string $pageClassName,
        string $zone,
        MigrationIdMap $idMap,
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
            $section = $this->draftWriter->createSection($migrationSection, $pageId, $pageClassName, $zone, $sectionSort);
            // Freshly written record carries a valid (positive) ID.
            /** @var positive-int $sectionId */
            $sectionId = (int) $section->ID;

            foreach ($migrationSection->rows as $migrationRow) {
                $row = $this->draftWriter->createRow($migrationRow, $sectionId);
                /** @var positive-int $rowId */
                $rowId = (int) $row->ID;

                foreach ($migrationRow->columns as $migrationColumn) {
                    $column = $this->draftWriter->createColumn($migrationColumn, $rowId);
                    /** @var positive-int $columnId */
                    $columnId = (int) $column->ID;

                    $elementSort = 1;
                    foreach ($migrationColumn->elements as $legacyElement) {
                        $newElement = $this->draftWriter->buildContentElement($legacyElement, $columnId, $elementSort);
                        $newElement->write();
                        $newElement->writeToStage(Versioned::LIVE);
                        $elementSort++;
                    }

                    $column->writeToStage(Versioned::LIVE);
                    $idMap->markContainerPublished($columnId);
                }

                $row->writeToStage(Versioned::LIVE);
                $idMap->markContainerPublished($rowId);
            }

            $section->writeToStage(Versioned::LIVE);
            $idMap->markContainerPublished($sectionId);
        }
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
}
