<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use RuntimeException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigratedElementContext;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\MigrationIdMap;

/**
 * Publishes migrated draft records to the LIVE stage: shared elements (published
 * from their draft record), live-only elements (created as a fresh hierarchy on
 * both stages), and live grid-settings reconciliation.
 *
 * Reuses {@see DraftHierarchyWriter} for the live-only hierarchy build (so the
 * `updateClassNameMapping` / `updateElementFieldMapping` extension hooks still
 * fire) and the {@see RowMappingStrategy} to lay out live-only elements the same
 * way the draft path does.
 *
 * Carries the `updateLiveElementFieldMapping`, `updateLiveRowFieldMapping` and
 * `updateLiveSectionFieldMapping` extension hooks (shared-record path only — see
 * {@see overwriteLiveContent()} and {@see reconcileLiveContainers()}), and fires
 * `onAfterMigrate` on every content element it writes to live — plus on draft
 * for the live-only elements it creates there.
 */
final class LivePublisher
{
    use Extensible;

    public function __construct(
        private readonly FieldMapper $mapper,
        private readonly DraftHierarchyWriter $draftWriter,
        private readonly LoggerInterface $logger,
        private readonly RowMappingStrategy $strategy,
        private readonly DroppedMediaCollector $droppedMedia,
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
        AfterMigrateHook $afterMigrate,
    ): void {
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

            // A row delimiter that exists on both stages has no record of its
            // own to publish — skip it here. Its Row and Section go live with
            // their content's container chain, and reconcileLiveContainers()
            // then gives them the live row's values.
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

            $this->publishContainerChain($newColumnId, $idMap);
            $this->reconcileColumnLiveGridSettings($newColumnId, $liveElement, $defaultViewport, $viewportKeyMap);

            $element = GridElement::get()->byID($newElementId);
            if ($element instanceof GridElement) {
                // Publish draft structure to live (creates _Live rows with
                // correct ID, ParentID, ParentClass, ClassName).
                $element->writeToStage(Versioned::LIVE);

                // Overwrite live content fields with live-specific values,
                // since writeToStage copied draft content to live. The Sort is
                // the column-local value assigned on draft (not the legacy
                // area-wide value) so both stages order the column identically.
                $this->overwriteLiveContent($element, $liveElement, $idMap->draftSort($oldId));

                // Reconciliation point for project fields mapped via
                // updateElementFieldMapping, which overwriteLiveContent does not
                // know about and writeToStage() copied from draft.
                $this->extend('updateLiveElementFieldMapping', $element, $liveElement);
                // extend() passes by reference, which widens both for PHPStan.
                /** @var GridElement $element */
                /** @var LegacyElement $liveElement */
                $afterMigrate->fire($element, $liveElement, MigratedElementContext::LIVE);
            }
        }

        $this->reconcileLiveContainers($liveElements, $idMap);

        if ($liveOnlyElements !== []) {
            $this->logger->info(
                'Page {pageId}: {liveOnlyCount} live-only legacy element(s) found; '
                . 'they are appended after the draft content — verify live layout.',
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
                $afterMigrate,
            );
        }
    }

    /**
     * Give each published Row and Section the values of its legacy row on live.
     *
     * writeToStage(LIVE) in {@see publishContainerChain()} copied the containers'
     * DRAFT values to live. For every legacy row on the live area that the draft
     * pass recorded against a Row or Section that is now on live, the stock
     * fields (Row Title and ExtraClass, Section ExtraClass from the row's
     * CustomSectionClass) are corrected with a raw `_Live` UPDATE — an empty
     * legacy Title becomes the Row's "Row N" default for its position — then the
     * `updateLiveRowFieldMapping` / `updateLiveSectionFieldMapping` hook fires
     * for the project's own fields. Like `updateLiveElementFieldMapping`, the
     * hook receives the DRAFT record and must write to the LIVE stage only.
     *
     * A container whose legacy row is missing from the live area keeps its draft
     * values on live. Live-only rows never reach this method: they are not in the
     * id map, and {@see createLiveOnlyHierarchy()} builds them from live data.
     *
     * @param list<LegacyElement> $liveElements
     */
    private function reconcileLiveContainers(array $liveElements, MigrationIdMap $idMap): void
    {
        foreach ($liveElements as $liveRow) {
            if (!$liveRow->isRow) {
                continue;
            }

            $rowId = $idMap->newRowId($liveRow->id);
            $row = $rowId !== null ? Row::get()->byID($rowId) : null;
            if ($row instanceof Row && $row->isPublished()) {
                // An empty legacy title takes the "Row N" default for the Row's
                // position, never the published draft Title: that is the draft
                // row's own title whenever only the draft was titled.
                $this->overwriteLiveGridElementFields((int) $row->ID, [
                    'Title' => $liveRow->title !== '' ? $liveRow->title : $idMap->rowDefaultTitle($liveRow->id),
                    'ExtraClass' => $liveRow->extraClass,
                ]);
                $this->extend('updateLiveRowFieldMapping', $row, $liveRow);
            }

            /** @var LegacyElement $liveRow */
            $sectionId = $idMap->newSectionId($liveRow->id);
            $section = $sectionId !== null ? Section::get()->byID($sectionId) : null;
            if ($section instanceof Section && $section->isPublished()) {
                $this->overwriteLiveGridElementFields((int) $section->ID, ['ExtraClass' => $liveRow->rowData->customSectionClass ?? '']);
                $this->extend('updateLiveSectionFieldMapping', $section, $liveRow);
            }
        }
    }

    /**
     * @param non-empty-array<'Title'|'ExtraClass', string> $values
     */
    private function overwriteLiveGridElementFields(int $id, array $values): void
    {
        $setClauses = \array_map(static fn (string $field): string => \sprintf('"%s" = ?', $field), \array_keys($values));

        DB::prepared_query(
            \sprintf(
                'UPDATE "%s_Live" SET %s WHERE "ID" = ?',
                DataObject::getSchema()->tableName(GridElement::class),
                \implode(', ', $setClauses),
            ),
            [...\array_values($values), $id],
        );
    }

    /**
     * Reconcile a published Column's live GridSettings from its element's live Size.
     *
     * The Column's GridSettings is derived from the DRAFT element Size in
     * {@see DraftHierarchyWriter::createColumn()} and copied to the live table by
     * writeToStage(LIVE). For an element shared between draft and live whose
     * legacy Size differs, this would leave the live Column showing the
     * draft-derived width. Here the live settings are recomputed from the live
     * Size (using the same mapper and viewport configuration the draft path
     * used) and written to the live stage only when they differ from the
     * published (draft-derived) settings. Each Column holds exactly one element,
     * so its live settings are that element's.
     *
     * The live row is updated with raw SQL (mirroring {@see overwriteLiveContent()})
     * rather than `setGridSettings()` + `writeToStage(LIVE)`: the ORM path,
     * starting from a draft-stage object, also rewrites the draft GridSettings,
     * which would corrupt the draft Column width. A targeted `_Live` UPDATE keeps
     * the draft untouched.
     *
     * @param array<string, string> $viewportKeyMap Old viewport key → new key
     */
    private function reconcileColumnLiveGridSettings(
        int $columnId,
        LegacyElement $liveElement,
        string $defaultViewport,
        array $viewportKeyMap,
    ): void {
        $column = Column::get()->byID($columnId);
        if (!$column instanceof Column) {
            return;
        }

        $liveSettings = $this->mapper->mapGridSettings($liveElement, $defaultViewport, $viewportKeyMap);

        // The loaded column is on the draft stage and carries the
        // draft-derived settings (also just copied to live by writeToStage).
        // Skip the write when the live Size produces the same settings — the
        // common case, and keeps the pass idempotent.
        if ($liveSettings->equals($column->getGridSettings())) {
            return;
        }

        // DBGridSettings stores the VO across four prefixed columns; Overrides
        // is NULL when empty, else JSON — matching DBGridSettings::applyGridSettings.
        $overrides = $liveSettings->overrides === []
            ? null
            : \json_encode($liveSettings->overrides, \JSON_THROW_ON_ERROR);

        $columnLiveTable = DataObject::getSchema()->tableName(Column::class) . '_Live';

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
     * Only the stock GridElement and ContentElement fields are corrected here.
     * Fields a project maps through `updateElementFieldMapping` were set from the
     * DRAFT legacy element, and writeToStage(LIVE) copied those draft values to
     * live — so where the two stages diverge, draft content would go live. The
     * `updateLiveElementFieldMapping` hook fired by the caller right after this
     * method is the reconciliation point for those fields; it receives the
     * published record and the live {@see LegacyElement}, and an
     * implementation must write to the LIVE stage only (raw `_Live` UPDATE, or
     * `Versioned::withVersionedMode()` pinned to LIVE) — writing the passed draft
     * record would push live values back onto draft.
     *
     * The live-only path needs no equivalent: those elements are built from the
     * live legacy data by {@see DraftHierarchyWriter}, which already fires
     * `updateElementFieldMapping` with live values.
     *
     * @param int $draftSort Column-local Sort assigned to this element on draft
     */
    private function overwriteLiveContent(GridElement $element, LegacyElement $liveElement, int $draftSort): void
    {
        $schema = DataObject::getSchema();
        $newElementId = $element->ID;

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

        // Text-block fields (HTML + media), mirroring the draft path's ContentElement gate
        if ($liveElement->mediaData === null || !$element instanceof ContentElement) {
            return;
        }

        $html = $liveElement->mediaData->fields['HTML'] ?? null;
        $liveFields = [
            'HTML' => \is_string($html) ? $html : '',
            ...$this->mapper->mapMediaFields($liveElement->mediaData)->toArray(),
        ];

        // The media fields live wherever the site applied BlockMediaExtension (or
        // nowhere); write each to the table holding it and leave the rest behind.
        $resolved = FieldTableResolver::resolve($element::class, \array_keys($liveFields));
        $this->droppedMedia->record($liveElement->id, $liveElement->mediaData, $resolved['missing']);

        // Field names are compile-time constants from MappedMediaFields::toArray(),
        // table names come from the schema; neither is user input.
        foreach ($resolved['byTable'] as $table => $fields) {
            $setClauses = [];
            $params = [];
            foreach ($fields as $field) {
                $setClauses[] = \sprintf('"%s" = ?', $field);
                $params[] = $liveFields[$field];
            }
            $params[] = $newElementId;

            DB::prepared_query(
                \sprintf('UPDATE "%s_Live" SET %s WHERE "ID" = ?', $table, \implode(', ', $setClauses)),
                $params,
            );
        }
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
     * same configured strategy (ElementGrouper row boundaries + one Column per
     * element) rather than given a dedicated chain each. Records are written
     * top-down (Section → Row → Column → Element) so each child can reference its
     * just-written parent ID. The resulting Sections are sorted after any Sections
     * already created on this page + zone from the draft path; both stages share one
     * Sort sequence, so the draft max-Sort yields the correct live append offset.
     *
     * Each element is recorded in the id map like a draft-path element, so a
     * later copy of it in the same run is handed it as its first copy.
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
        AfterMigrateHook $afterMigrate,
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

                    $legacyElement = $migrationColumn->element;
                    $newElement = $this->draftWriter->buildContentElement($legacyElement, $columnId, 1);
                    $newElement->write();
                    /** @var positive-int $newElementId */
                    $newElementId = (int) $newElement->ID;
                    $idMap->recordElement($legacyElement->id, $newElementId, $columnId, 1);
                    $afterMigrate->fire($newElement, $legacyElement, MigratedElementContext::DRAFT);
                    $newElement->writeToStage(Versioned::LIVE);
                    $afterMigrate->fire($newElement, $legacyElement, MigratedElementContext::LIVE);

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
