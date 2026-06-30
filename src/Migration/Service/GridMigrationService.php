<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use Throwable;
use RuntimeException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\MigrationIdMap;

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

    private readonly DraftHierarchyWriter $draftWriter;

    private readonly LivePublisher $livePublisher;

    private readonly PageGridFlagWriter $pageFlagWriter;

    public function __construct(
        private readonly LegacyElementSource $reader,
        FieldMapper $mapper,
        private readonly RowMappingStrategy $strategy,
        private readonly LoggerInterface $logger,
    ) {
        $this->draftWriter = new DraftHierarchyWriter($mapper);
        $this->livePublisher = new LivePublisher($mapper, $this->draftWriter, $logger, $strategy);
        $this->pageFlagWriter = new PageGridFlagWriter($reader, $logger);
    }

    /**
     * Run the migration for all eligible pages (or a filtered subset).
     *
     * **Per-page atomicity**: each page is wrapped in its own database transaction
     * ({@see migratePage()}). A failure mid-batch leaves already-migrated pages
     * committed; they will be skipped on a re-run via the idempotency guard in
     * {@see hasExistingSections()}. The batch continues past the failure by
     * default; pass `$stopOnFirstFailure = true` to halt after the first page that
     * fails.
     *
     * @param string $defaultViewport Old viewport key used as default (e.g. 'MD')
     * @param string $zone Zone name for created Sections
     * @param array<string, string> $viewportKeyMap Old viewport key → new key (e.g. 'MD' → 'md')
     * @param bool $dryRun When true, log what would be created without writing
     * @param list<int>|null $pageIds Optional filter to restrict to specific pages
     * @param bool $reconcileDisabledPages Carry over UseGrid = 0 for grid-disabled pages.
     *     The Fluent orchestrator runs this once (on the default-locale pass) because the
     *     UseGrid writes target locale-invariant base/_Live tables; running it per locale
     *     would repeat identical writes and log lines N times.
     * @param bool $stopOnFirstFailure When true, halt the batch after the first page that
     *     fails to migrate. Already-migrated pages remain committed and are skippable on
     *     re-run via the idempotency guard. Note: halts only the per-page loop —
     *     {@see migrateDisabledGridPages()} still runs afterward (it writes UseGrid = 0
     *     only on already-disabled pages, a disjoint set, so it is safe to continue).
     * @return int<0, max> Number of pages that failed to migrate
     */
    public function run(
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun = false,
        ?array $pageIds = null,
        bool $reconcileDisabledPages = true,
        bool $stopOnFirstFailure = false,
    ): int {
        $eligiblePages = $this->reader->getEligiblePages('draft', $pageIds);
        $failures = 0;
        /** @var list<int> $succeeded */
        $succeeded = [];
        /** @var list<int> $failed */
        $failed = [];

        // Suppress Section and Row auto-scaffolding for the entire batch.
        // Auto-scaffolding (onAfterWrite hooks on Section and Row) would insert
        // duplicate child records alongside the hierarchy the migration writes
        // explicitly. Suppressing it once here — rather than toggling per-page —
        // minimises toggle points and protects every write in the batch, including
        // migrateDisabledGridPages() below.
        //
        // OFFLINE / NO-CONCURRENCY ASSUMPTION: this mutates SilverStripe's
        // process-wide Config for the duration of the batch. It is safe only when
        // no other PHP process (e.g. a web request) writes Section or Row records
        // concurrently. Run the migration during a maintenance window or against
        // an offline dataset.
        $sectionAutoScaffold = (bool) Section::config()->get('auto_scaffold');
        $rowAutoScaffold = (bool) Row::config()->get('auto_scaffold');
        Section::config()->set('auto_scaffold', false);
        Row::config()->set('auto_scaffold', false);

        try {
            foreach ($eligiblePages as $pageInfo) {
                $pageId = $pageInfo['pageId'];
                $areaId = $pageInfo['areaId'];
                $pageClassName = $pageInfo['pageClassName'];

                try {
                    $this->migratePage($pageId, $areaId, $pageClassName, $zone, $dryRun, $defaultViewport, $viewportKeyMap);
                    $succeeded[] = $pageId;
                } catch (Throwable $exception) {
                    $failures++;
                    $failed[] = $pageId;
                    $this->logger->error('Migration failed for page {pageId}: {message}', [
                        'pageId' => $pageId,
                        'areaId' => $areaId,
                        'message' => $exception->getMessage(),
                        'exception' => $exception,
                    ]);

                    if ($stopOnFirstFailure) {
                        break;
                    }
                }
            }

            if ($failed === []) {
                $this->logger->info(
                    'Migration batch complete: {succeeded} page(s) succeeded, 0 failed.',
                    ['succeeded' => \count($succeeded)],
                );
            } else {
                $this->logger->warning(
                    'Migration batch complete: {succeeded} page(s) succeeded, {failedCount} failed (page IDs: {failedIds}).',
                    [
                        'succeeded' => \count($succeeded),
                        'failedCount' => \count($failed),
                        'failedIds' => \implode(', ', $failed),
                    ],
                );
            }

            if (!$dryRun && $reconcileDisabledPages) {
                $this->pageFlagWriter->migrateDisabledGridPages();
            }
        } finally {
            Section::config()->set('auto_scaffold', $sectionAutoScaffold);
            Row::config()->set('auto_scaffold', $rowAutoScaffold);
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
     * @param string $defaultViewport Old viewport key used as default (e.g. 'MD'), for live grid-settings reconciliation
     * @param array<string, string> $viewportKeyMap Old viewport key → new key, for live grid-settings reconciliation
     */
    private function migratePage(
        int $pageId,
        int $areaId,
        string $pageClassName,
        string $zone,
        bool $dryRun,
        string $defaultViewport,
        array $viewportKeyMap,
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
        $sections = $draftElements !== []
            ? $this->strategy->buildHierarchy($draftElements, $pageId, $zone)
            : [];

        // Step 5: Dry-run — log and return
        if ($dryRun) {
            $this->logDryRun($pageId, $sections);
            return;
        }

        // No draft sections and no live elements means nothing to migrate
        $liveElements = $this->reader->getElementsForArea($areaId, 'live');
        if ($sections === [] && $liveElements === []) {
            $this->logger->info('Page {pageId} has no elements to migrate.', ['pageId' => $pageId]);
            return;
        }

        // Steps 6-8: Transaction-wrapped write
        $conn = DB::get_conn();
        if ($conn === null) {
            throw new RuntimeException('No database connection available for migration.');
        }

        $conn->withTransaction(function () use (
            $pageId,
            $pageClassName,
            $zone,
            $sections,
            $draftElements,
            $liveElements,
            $defaultViewport,
            $viewportKeyMap,
        ): void {
            // A single MigrationIdMap carries the legacy→new id/sort maps and the
            // published-container bookkeeping across the draft write and the live
            // publish stage (previously four shared by-ref arrays).
            $idMap = new MigrationIdMap();

            $this->writeDraftStage($pageId, $pageClassName, $zone, $sections, $idMap);

            if ($liveElements !== []) {
                $this->publishLiveStage(
                    $pageId,
                    $pageClassName,
                    $zone,
                    $liveElements,
                    $draftElements,
                    $idMap,
                    $defaultViewport,
                    $viewportKeyMap,
                );
            }

            $this->pageFlagWriter->setUseGridOnPage($pageId, true, includeLive: $liveElements !== []);
        });

        $this->logger->info('Successfully migrated page {pageId}.', ['pageId' => $pageId]);
    }

    /**
     * Write the draft Section/Row/Column hierarchy on the DRAFT stage.
     *
     * @param class-string $pageClassName
     * @param list<MigrationSection> $sections
     */
    private function writeDraftStage(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $sections,
        MigrationIdMap $idMap,
    ): void {
        Versioned::withVersionedMode(function () use (
            $pageId,
            $pageClassName,
            $zone,
            $sections,
            $idMap,
        ): void {
            Versioned::set_stage(Versioned::DRAFT);
            $this->draftWriter->writeDraftHierarchy($pageId, $pageClassName, $zone, $sections, $idMap);
        });
    }

    /**
     * Publish draft records to live for elements that also existed on legacy live.
     *
     * Stage is set to DRAFT because we read the draft-created records and then
     * call writeToStage(LIVE) to copy them to _Live tables. overwriteLiveContent()
     * then corrects _Live with live-specific values.
     *
     * @param class-string $pageClassName
     * @param list<LegacyElement> $liveElements
     * @param list<LegacyElement> $draftElements
     * @param array<string, string> $viewportKeyMap Old viewport key → new key, for live grid-settings reconciliation
     */
    private function publishLiveStage(
        int $pageId,
        string $pageClassName,
        string $zone,
        array $liveElements,
        array $draftElements,
        MigrationIdMap $idMap,
        string $defaultViewport,
        array $viewportKeyMap,
    ): void {
        // Build the set of legacy DRAFT element IDs (rows AND content
        // elements). A live element is "live-only" only when its legacy
        // ID has no draft counterpart in this set; elements present on
        // both legacy stages are "shared" and must be published from the
        // draft records, never re-created as a live-only hierarchy.
        //
        // The id map cannot be used for this test: it only holds content
        // elements (row delimiters are grouping boundaries and are never
        // written as records), so a shared row would be misread as
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
            $idMap,
            $defaultViewport,
            $viewportKeyMap,
        ): void {
            Versioned::set_stage(Versioned::DRAFT);
            $this->livePublisher->publishToLive(
                $pageId,
                $pageClassName,
                $zone,
                $liveElements,
                $draftLegacyIds,
                $idMap,
                $defaultViewport,
                $viewportKeyMap,
            );
        });
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
