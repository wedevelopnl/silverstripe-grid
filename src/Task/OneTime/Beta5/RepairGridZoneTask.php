<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Task\OneTime\Beta5;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\Connect\DBSchemaManager;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Service\GridZoneResolver;
use WeDevelop\Grid\Task\OneTime\Beta4\BackfillGridZoneTask;

/**
 * One-time upgrade: repairs grid elements whose Zone breaks the rule
 * {@see GridElement::validate()} now enforces — non-empty exactly when the
 * element sits directly on a page.
 *
 * Rows written before the rule existed can break it either way. A page root
 * with no zone renders in no zone at all; a nested element with one is
 * invisible to every root read. Both now also block every write that
 * re-validates them — publishing, duplicating or copying the page.
 *
 * Fixed where the answer is unambiguous, on all three versioned stages:
 * - a zone-less root on a page that declares exactly one zone gets that zone,
 *   appended after the zone's existing Sort sequence, relative order kept;
 * - a zoned element under any existing non-page class loses its zone.
 * Everything else is listed for manual review and makes the run fail, because
 * those pages cannot be published until someone decides.
 *
 * Each stage's row is judged by its OWN parent: live and history rows can
 * point at a different parent than draft. Live is fixed in place, never by
 * publishing, so unrelated pending draft edits stay unpublished.
 *
 * Raw SQL throughout: GridElement is Fluent-isolated, so the ORM sees one
 * locale at a time, and an ORM write would trip the very rule being repaired.
 * The statements are MySQL syntax — the only database this module is
 * developed and tested against — though none of them is MySQL-specific beyond
 * identifier quoting; a PostgreSQL site should review them before running.
 */
class RepairGridZoneTask extends BuildTask
{
    /** @var array<string, string> Stage table suffix => label used in the report. */
    private const array STAGES = ['' => 'draft', '_Live' => 'live', '_Versions' => 'history'];

    protected static string $commandName = 'repair-grid-zone';

    protected string $title = 'Repair grid Zone';

    protected static string $description = 'Gives zone-less page roots their page\'s only zone and clears zones below page level. Lists what it cannot decide. Safe to re-run.';

    /** @var array<string, string> */
    private static array $dependencies = [
        'zoneResolver' => '%$' . GridZoneResolver::class,
    ];

    public GridZoneResolver $zoneResolver;

    /**
     * The zone each page resolves to, or the reason it does not, keyed by page
     * ID. Shared across the three stages: the zones a page declares come from
     * its code, not from a stage.
     *
     * @var array<int, array{zone: non-empty-string}|array{reason: non-empty-string}>
     */
    private array $resolvedPages = [];

    /** @var list<string> */
    private array $manual = [];

    /** @return list<InputOption> */
    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be repaired without writing'),
        ];
    }

    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $this->resolvedPages = [];
        $this->manual = [];

        $table = DataObject::getSchema()->tableName(GridElement::class);

        if ($table === null) {
            $output->writeln('<error>GridElement has no table — run dev/build first.</error>');

            return Command::FAILURE;
        }

        $pendingBackfill = BackfillGridZoneTask::singleton()->pendingCopyCount();

        if ($pendingBackfill > 0) {
            $output->writeln(sprintf(
                '<error>%d row(s) still have a legacy zone to copy. Run "sake tasks:backfill-grid-zone" first.</error>',
                $pendingBackfill,
            ));

            return Command::FAILURE;
        }

        $repaired = 0;

        foreach (self::STAGES as $suffix => $stage) {
            if (!$this->dbSchema()->hasTable($table . $suffix)) {
                continue;
            }

            $stageTable = new StageTable($table . $suffix, $suffix === '_Versions', $stage);
            $repaired += $this->clearMisplacedZones($stageTable, $dryRun, $output);
            $repaired += $this->assignMissingZones($stageTable, $dryRun, $output);
        }

        $this->report($repaired, $dryRun, $output);

        return $this->manual === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** Zoned rows whose parent is not a page: clear the zone where the parent class still exists. */
    private function clearMisplacedZones(StageTable $table, bool $dryRun, PolyOutput $output): int
    {
        $pageClasses = $this->pageClasses();
        $rows = $table->select(
            sprintf(
                '"Zone" IS NOT NULL AND "Zone" != \'\' AND ("ParentClass" IS NULL OR "ParentClass" NOT IN (%s))',
                DB::placeholders($pageClasses),
            ),
            $pageClasses,
        );

        $cleared = 0;

        foreach ($rows as $row) {
            $parentClass = $row->parentClass;

            if ($parentClass === '' || !ClassInfo::exists($parentClass)) {
                $this->manual[] = $row->describe(
                    $parentClass === '' ? 'the element has no parent' : 'the parent class no longer exists',
                );

                continue;
            }

            $output->writeln(sprintf(
                '%s zone "%s" on %s, parent %s #%d',
                $dryRun ? 'Would clear' : 'Cleared',
                $row->zone,
                $row->label(),
                $parentClass,
                $row->parentId,
            ));

            if (!$dryRun) {
                $table->update($row, null, null);
            }

            $cleared++;
        }

        return $cleared;
    }

    /** Zone-less rows directly on a page: give them the page's zone when it has exactly one. */
    private function assignMissingZones(StageTable $table, bool $dryRun, PolyOutput $output): int
    {
        $pageClasses = $this->pageClasses();
        $rows = $table->select(
            sprintf('("Zone" IS NULL OR "Zone" = \'\') AND "ParentClass" IN (%s)', DB::placeholders($pageClasses)),
            $pageClasses,
        );

        $byPage = [];
        foreach ($rows as $row) {
            $byPage[$row->parentClass . ':' . $row->parentId][] = $row;
        }

        $assigned = 0;

        foreach ($byPage as $pageRows) {
            $first = $pageRows[0];
            $resolved = $this->resolvePage($first->parentId);

            if (isset($resolved['reason'])) {
                foreach ($pageRows as $row) {
                    $this->manual[] = $row->describe($resolved['reason']);
                }

                continue;
            }

            $assigned += $this->appendToZone($table, $pageRows, $resolved['zone'], $dryRun, $output);
        }

        return $assigned;
    }

    /**
     * Appends $rows after the zone's current last root, in their current
     * order. History rows of one record share a Sort, like the record did.
     *
     * @param non-empty-list<StageRow> $rows Ordered by Sort, then identity.
     * @param non-empty-string $zone
     */
    private function appendToZone(StageTable $table, array $rows, string $zone, bool $dryRun, PolyOutput $output): int
    {
        $first = $rows[0];
        $sort = $table->maxSort($first->parentClass, $first->parentId, $zone);
        $sortByElement = [];

        foreach ($rows as $row) {
            $sortByElement[$row->elementId] ??= ++$sort;

            $output->writeln(sprintf(
                '%s zone "%s" on %s, page %s #%d',
                $dryRun ? 'Would set' : 'Set',
                $zone,
                $row->label(),
                $row->parentClass,
                $row->parentId,
            ));

            if (!$dryRun) {
                $table->update($row, $zone, $sortByElement[$row->elementId]);
            }
        }

        return count($rows);
    }

    /**
     * @return array{zone: non-empty-string}|array{reason: non-empty-string}
     */
    private function resolvePage(int $pageId): array
    {
        if (isset($this->resolvedPages[$pageId])) {
            return $this->resolvedPages[$pageId];
        }

        // A page type whose form cannot be built outside a request must not
        // abort the whole repair: the failure is caught HERE, reported against
        // the page in the manual-review list (so it is never silent), and the
        // run carries on with the other pages.
        try {
            $zones = $this->zonesOf($pageId);
        } catch (Throwable $throwable) {
            return $this->resolvedPages[$pageId] = ['reason' => 'its grid areas could not be read: ' . $throwable->getMessage()];
        }

        return $this->resolvedPages[$pageId] = match (true) {
            $zones === null => ['reason' => 'the page does not exist'],
            $zones === [] => ['reason' => $this->gridSwitchedOff($pageId)
                ? 'the page has its grid switched off (UseGrid = 0)'
                : 'the page declares no grid area'],
            count($zones) > 1 => ['reason' => sprintf('the page has several grid areas (%s)', implode(', ', $zones))],
            default => ['zone' => $zones[0]],
        };
    }

    /**
     * The zones the page declares, across every locale it exists in, or null
     * when it exists in none. Locales are united rather than trusted
     * one-by-one: if they disagree, there is no single answer to apply.
     *
     * @return list<non-empty-string>|null
     */
    private function zonesOf(int $pageId): ?array
    {
        $locales = $this->localesOf($pageId);

        if ($locales === []) {
            $page = $this->draftPage($pageId);

            return $page === null ? null : $this->zoneResolver->zonesFor($page);
        }

        $zones = null;

        foreach ($locales as $locale) {
            $page = FluentState::singleton()->withState(function (FluentState $state) use ($locale, $pageId): ?SiteTree {
                $state->setLocale($locale);

                return $this->draftPage($pageId);
            });

            if ($page !== null) {
                $zones = [...$zones ?? [], ...$this->zoneResolver->zonesFor($page)];
            }
        }

        return $zones === null ? null : array_values(array_unique($zones));
    }

    /**
     * Locales holding a localised row of the page; empty without Fluent, or
     * when Fluent does not localise pages.
     *
     * Read from Fluent's `<base table>_Localised` table directly: whether a
     * page is localised is a property of the schema, and the table is the
     * cheapest answer that needs no Fluent API beyond FluentState.
     *
     * @return list<string>
     */
    private function localesOf(int $pageId): array
    {
        $localisedTable = DataObject::getSchema()->baseDataTable(SiteTree::class) . '_Localised';

        if (!class_exists(FluentState::class) || !$this->dbSchema()->hasTable($localisedTable)) {
            return [];
        }

        /** @var list<string> */
        return DB::prepared_query(
            sprintf('SELECT DISTINCT "Locale" FROM "%s" WHERE "RecordID" = ? ORDER BY "Locale"', $localisedTable),
            [$pageId],
        )->column();
    }

    private function draftPage(int $pageId): ?SiteTree
    {
        return Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });
    }

    private function gridSwitchedOff(int $pageId): bool
    {
        $page = $this->draftPage($pageId);

        return $page !== null && $page->hasExtension(GridPageExtension::class) && !$page->usesGrid();
    }

    /** @return non-empty-list<string> Every SiteTree class, the only parents a zone belongs under. */
    private function pageClasses(): array
    {
        /** @var non-empty-list<string> */
        return array_values(ClassInfo::subclassesFor(SiteTree::class));
    }

    private function report(int $repaired, bool $dryRun, PolyOutput $output): void
    {
        if ($this->manual !== []) {
            $output->writeln('<comment>Left for manual review:</comment>');

            foreach ($this->manual as $line) {
                $output->writeln('  - ' . $line);
            }
        }

        $output->writeln(sprintf(
            $dryRun
                ? '<info>Dry run: %d row(s) would be repaired, %d row(s) left for manual review.</info>'
                : '<info>Done: %d row(s) repaired, %d row(s) left for manual review.</info>',
            $repaired,
            count($this->manual),
        ));
    }

    /** The connection's schema manager, which is only ever null before boot. */
    private function dbSchema(): DBSchemaManager
    {
        $dbSchema = DB::get_schema();
        assert($dbSchema instanceof DBSchemaManager);

        return $dbSchema;
    }
}
