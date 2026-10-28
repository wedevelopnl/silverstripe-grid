<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Task\OneTime\Beta6;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Task\OneTime\ReadsDatabaseSchema;

/**
 * One-time upgrade: removes placements of shared blocks that no longer exist.
 *
 * Releases 6.0.0-beta.4 and beta.5 deleted a block in the active locale only,
 * leaving every other locale's placements pointing at a block record that was
 * gone. Such a placement renders nothing, yet it breaks the page editor: at
 * page level it is invisible and cannot be removed, inside a row it throws.
 *
 * Only placements whose block is gone from BOTH stages are removed — a block
 * that exists only in draft is merely unpublished, and its placements will
 * render once it is published. Each is removed from draft, live and history,
 * in every locale, and its page is listed for an editor to check. The block's
 * own orphaned content is left alone: it may be content an earlier "keep a
 * copy" delete lost, and nothing records which mode was chosen.
 *
 * Raw SQL throughout: GridElement is Fluent-isolated, so the ORM sees one
 * locale at a time. A placement row whose element row is missing cannot be
 * tied to a page or a locale; it is listed for manual review and fails the
 * run. The statements are MySQL syntax, as in
 * {@see \WeDevelop\Grid\Task\OneTime\Beta5\RepairGridZoneTask}.
 */
class RepairSharedBlockPlacementsTask extends BuildTask
{
    use ReadsDatabaseSchema;

    /** @var array<string, string> Stage table suffix => stage key. */
    private const array STAGES = ['' => 'draft', '_Live' => 'live'];

    protected static string $commandName = 'repair-shared-block-placements';

    protected string $title = 'Repair shared block placements';

    protected static string $description = 'Removes placements of deleted shared blocks on every stage and locale, and lists their pages for an editor to check. Safe to re-run.';

    /** @return list<InputOption> */
    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be removed without writing'),
        ];
    }

    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var array<int, array<string, array{parentClass: string, parentId: int, localeId: int}>> $stranded */
        $stranded = [];
        /** @var list<string> $manual */
        $manual = [];
        /** @var array<int, true> $unclassified */
        $unclassified = [];

        foreach (self::STAGES as $suffix => $stage) {
            foreach ($this->strandedRows($suffix) as $row) {
                if ($row['parentClass'] === null) {
                    $manual[] = $this->describeUnclassifiable($row['id'], $stage);
                    $unclassified[$row['id']] = true;

                    continue;
                }

                $stranded[$row['id']][$stage] = [
                    'parentClass' => $row['parentClass'],
                    'parentId' => $row['parentId'],
                    'localeId' => $row['localeId'],
                ];
            }
        }

        // A placement left to a person stays whole, even where its other stage
        // could have been classified.
        $stranded = array_diff_key($stranded, $unclassified);

        if ($stranded === [] && $manual === []) {
            $output->writeln(_t(
                self::class . '.NOTHING',
                'Nothing to repair: no placement of a deleted shared block is left.',
            ));

            return Command::SUCCESS;
        }

        $output->writeln($dryRun
            ? _t(self::class . '.PREVIEW', 'Preview only: nothing is changed.')
            : _t(self::class . '.IRREVERSIBLE', 'This removes database rows and cannot be undone except from a backup.'));

        $this->reportPages($stranded, $dryRun, $output);

        if (!$dryRun && $stranded !== []) {
            $this->remove(array_keys($stranded));
        }

        $this->reportManual($manual, $output);

        return $manual === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Placement rows on one stage whose block exists on neither stage, with the
     * parent and locale their element row holds — or a null parent when that
     * row is missing.
     *
     * @return list<array{id: int, parentClass: string|null, parentId: int, localeId: int}>
     */
    private function strandedRows(string $suffix): array
    {
        $referenceTable = $this->table(SharedBlockReference::class);
        $elementTable = $this->table(GridElement::class);
        $blockTable = $this->table(SharedBlock::class);

        if (!$this->dbSchema()->hasTable($referenceTable . $suffix)) {
            return [];
        }

        $locale = $this->isLocalised($elementTable . $suffix) ? 'e."LocaleID"' : '0';

        $query = DB::query(sprintf(
            'SELECT r."ID", e."ID" AS "ElementID", e."ParentClass", e."ParentID", %1$s AS "LocaleID" '
            . 'FROM "%2$s%5$s" r LEFT JOIN "%3$s%5$s" e ON e."ID" = r."ID" '
            . 'WHERE NOT EXISTS (SELECT 1 FROM "%4$s" b WHERE b."ID" = r."BlockID") '
            . 'AND NOT EXISTS (SELECT 1 FROM "%4$s_Live" b WHERE b."ID" = r."BlockID") '
            . 'ORDER BY r."ID"',
            $locale,
            $referenceTable,
            $elementTable,
            $blockTable,
            $suffix,
        ));

        $rows = [];

        /** @var array{ID: int|string, ElementID: int|string|null, ParentClass: string|null, ParentID: int|string|null, LocaleID: int|string|null} $record */
        foreach ($query as $record) {
            $rows[] = [
                'id' => (int) $record['ID'],
                'parentClass' => $record['ElementID'] === null ? null : (string) $record['ParentClass'],
                'parentId' => (int) $record['ParentID'],
                'localeId' => (int) $record['LocaleID'],
            ];
        }

        return $rows;
    }

    /**
     * One line per page and locale, naming the stages its placements were on.
     *
     * @param array<int, array<string, array{parentClass: string, parentId: int, localeId: int}>> $stranded
     */
    private function reportPages(array $stranded, bool $dryRun, PolyOutput $output): void
    {
        if ($stranded === []) {
            return;
        }

        /** @var array<string, array{label: string, stages: array<string, true>}> $pages */
        $pages = [];

        foreach ($stranded as $rows) {
            foreach ($rows as $stage => $row) {
                $locale = $this->localeCode($row['localeId']);
                $pageId = $this->pageIdOf($row['parentClass'], $row['parentId'], $stage);
                $key = $locale . ':' . ($pageId ?? $row['parentClass'] . '#' . $row['parentId']);

                $pages[$key] ??= ['label' => $this->pageLabel($pageId, $row, $locale), 'stages' => []];
                $pages[$key]['stages'][$stage] = true;
            }
        }

        $output->writeln(_t(self::class . '.HEADER', 'Leftover placements of deleted shared blocks:'));

        foreach ($pages as $page) {
            $output->writeln('  ' . $page['label'] . ' ' . $this->stagesLabel($page['stages']));
        }

        $params = [
            'placements' => _t(self::class . '.PLACEMENTS', '{count} placement|{count} placements', ['count' => count($stranded)]),
            'pages' => _t(self::class . '.PAGES', '{count} page|{count} pages', ['count' => count($pages)]),
        ];

        $output->writeln($dryRun
            ? _t(
                self::class . '.SUMMARY_DRY_RUN',
                '{placements} on {pages} would be removed. Their pages show visitors the same as before. Ask an editor to check them.',
                $params,
            )
            : _t(
                self::class . '.SUMMARY',
                '{placements} on {pages} removed. Their pages show visitors the same as before. Ask an editor to check them.',
                $params,
            ));
    }

    /**
     * The page a placement sits on, walking up its containers on the same
     * stage; null when the chain breaks before a page.
     */
    private function pageIdOf(string $parentClass, int $parentId, string $stage): ?int
    {
        $elementTable = $this->table(GridElement::class) . array_flip(self::STAGES)[$stage];

        // Bounded by the depth of the hierarchy: page > Section > Row > Column.
        for ($depth = 0; $depth < 4 && is_a($parentClass, GridElement::class, true); $depth++) {
            $query = DB::prepared_query(
                sprintf('SELECT "ParentClass", "ParentID" FROM "%s" WHERE "ID" = ?', $elementTable),
                [$parentId],
            );

            $parent = null;

            /** @var array{ParentClass: string|null, ParentID: int|string|null} $record */
            foreach ($query as $record) {
                $parent = $record;
            }

            if ($parent === null) {
                return null;
            }

            $parentClass = (string) $parent['ParentClass'];
            $parentId = (int) $parent['ParentID'];
        }

        return is_a($parentClass, SiteTree::class, true) ? $parentId : null;
    }

    /** @param array{parentClass: string, parentId: int, localeId: int} $row */
    private function pageLabel(?int $pageId, array $row, string $locale): string
    {
        $prefix = $locale === '' ? '' : $locale . ' ';

        if ($pageId === null) {
            return $prefix . _t(
                self::class . '.NO_PAGE',
                '(no page) under {class} #{id}',
                ['class' => $row['parentClass'], 'id' => $row['parentId']],
            );
        }

        return $prefix . _t(
            self::class . '.PAGE',
            '{title} (page {id})',
            ['title' => $this->pageTitle($pageId, $locale), 'id' => $pageId],
        );
    }

    /** The page's title in $locale, draft first; a deleted page has none. */
    private function pageTitle(int $pageId, string $locale): string
    {
        $read = static function () use ($pageId): string {
            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                $page = Versioned::get_by_stage(SiteTree::class, $stage)->byID($pageId);

                if ($page !== null) {
                    return (string) $page->Title;
                }
            }

            return _t(self::class . '.DELETED_PAGE', '(deleted page)');
        };

        if ($locale === '') {
            return $read();
        }

        return FluentState::singleton()->withState(static function (FluentState $state) use ($locale, $read): string {
            $state->setLocale($locale);

            return $read();
        });
    }

    /** @param array<string, true> $stages */
    private function stagesLabel(array $stages): string
    {
        if (count($stages) === 2) {
            return _t(self::class . '.STAGE_BOTH', 'draft and published');
        }

        return isset($stages['live'])
            ? _t(self::class . '.STAGE_LIVE', 'published')
            : _t(self::class . '.STAGE_DRAFT', 'draft');
    }

    /**
     * Every row of each placement, in every table its class spans, on draft,
     * live and history — together, so a failure leaves nothing half removed.
     *
     * @param list<int> $ids
     */
    private function remove(array $ids): void
    {
        $tables = [];

        /** @var class-string<DataObject> $class */
        foreach (ClassInfo::dataClassesFor(SharedBlockReference::class) as $class) {
            $table = $this->table($class);

            foreach (['' => 'ID', '_Live' => 'ID', '_Versions' => 'RecordID'] as $suffix => $key) {
                if ($this->dbSchema()->hasTable($table . $suffix)) {
                    $tables[$table . $suffix] = $key;
                }
            }
        }

        $connection = DB::get_conn();
        assert($connection !== null);

        $connection->withTransaction(static function () use ($ids, $tables): void {
            foreach ($tables as $table => $key) {
                DB::prepared_query(
                    sprintf('DELETE FROM "%s" WHERE "%s" IN (%s)', $table, $key, DB::placeholders($ids)),
                    $ids,
                );
            }
        });
    }

    /** @param list<string> $manual */
    private function reportManual(array $manual, PolyOutput $output): void
    {
        if ($manual === []) {
            return;
        }

        $output->writeln(_t(self::class . '.MANUAL', 'Left for manual review:'));

        foreach ($manual as $line) {
            $output->writeln('  - ' . $line);
        }
    }

    private function describeUnclassifiable(int $id, string $stage): string
    {
        return _t(
            self::class . '.UNCLASSIFIABLE',
            '{class} #{id} ({stage}): its element row is missing, so its page cannot be told',
            ['class' => SharedBlockReference::class, 'id' => $id, 'stage' => $stage],
        );
    }

    /** The locale code of a LocaleID, or '' without Fluent isolation. */
    private function localeCode(int $localeId): string
    {
        if ($localeId <= 0 || !class_exists(Locale::class)) {
            return '';
        }

        foreach (Locale::getCached() as $locale) {
            if ($locale->ID === $localeId) {
                return $locale->Locale;
            }
        }

        return '';
    }

    /** Whether Fluent isolation added a LocaleID column to $table. */
    private function isLocalised(string $table): bool
    {
        return array_key_exists('LocaleID', $this->dbSchema()->fieldList($table));
    }

    /** @param class-string<DataObject> $class */
    private function table(string $class): string
    {
        return (string) DataObject::getSchema()->tableName($class);
    }
}
