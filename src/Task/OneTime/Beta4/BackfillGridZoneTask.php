<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Task\OneTime\Beta4;

use Override;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\Connect\DBSchemaManager;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlockReference;

/**
 * One-time upgrade: copies Zone from the root-element subclass tables to the
 * GridElement base table, where Zone now lives.
 *
 * Moving a $db field between classes leaves SilverStripe's schema step with
 * nothing to do about the DATA: dev/build adds the new base column empty and
 * leaves the old subclass column in place, so every page's zone assignment
 * would read as '' and the whole grid would vanish from its zones. This task
 * copies it across, on all three versioned stages.
 *
 * Idempotent: it only fills base rows whose Zone is still empty, so a second
 * run is a no-op and an operator who has already re-zoned a page by hand is
 * not overwritten.
 *
 * The copy is a multi-table UPDATE ... INNER JOIN, which is MySQL syntax —
 * matching the only database this module is developed and tested against. On
 * PostgreSQL the equivalent is UPDATE ... FROM, so a site running one needs the
 * three statements rewritten by hand; the SELECT COUNT reported by --dry-run is
 * portable and still tells you how many rows are affected.
 */
class BackfillGridZoneTask extends BuildTask
{
    /** @var list<string> */
    private const array STAGE_SUFFIXES = ['', '_Live', '_Versions'];

    protected static string $commandName = 'backfill-grid-zone';

    protected string $title = 'Backfill grid Zone';

    protected static string $description = 'Copies Zone from the Section/SharedBlockReference tables to the GridElement base table after the Zone hoist. Safe to re-run.';

    /** @return list<InputOption> */
    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be copied without writing'),
        ];
    }

    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $schema = DataObject::getSchema();
        $target = $schema->tableName(GridElement::class);

        if ($target === null) {
            $output->writeln('<error>GridElement has no table — run dev/build first.</error>');

            return Command::FAILURE;
        }

        $total = 0;

        foreach ([Section::class, SharedBlockReference::class] as $sourceClass) {
            $source = $schema->tableName($sourceClass);

            // Unreachable while both classes declare a $table_name; guarded
            // because tableName() is nullable for classes that do not.
            if ($source === null) {
                continue;
            }

            foreach (self::STAGE_SUFFIXES as $suffix) {
                $sourceTable = $this->resolveSourceTable($source, $suffix, $output);

                if ($sourceTable === null) {
                    continue;
                }

                $total += $this->copyStage($sourceTable, $target . $suffix, $suffix, $dryRun, $output);
            }
        }

        $output->writeln($dryRun
            ? sprintf('<info>Dry run: %d row(s) would be updated.</info>', $total)
            : sprintf('<info>Done: %d row(s) updated.</info>', $total));

        return Command::SUCCESS;
    }

    /** The connection's schema manager, which is only ever null before boot. */
    private function dbSchema(): DBSchemaManager
    {
        $dbSchema = DB::get_schema();
        assert($dbSchema instanceof DBSchemaManager);

        return $dbSchema;
    }

    /**
     * The table still holding the old Zone values for $baseTable at this stage.
     *
     * dev/build disposes of a vacated subclass table in one of two ways, and the
     * upgrade has to survive both. Section declared nothing but Zone, so losing
     * it left the class with no own fields and the whole table was RENAMED to
     * `_obsolete_…`. SharedBlockReference still declares BlockID, so its table
     * survived under its own name with Zone left behind as an obsolete column.
     * Checking only the live name would silently find nothing for Section and
     * strand every page's zone assignment.
     */
    private function resolveSourceTable(string $baseTable, string $suffix, PolyOutput $output): ?string
    {
        $dbSchema = $this->dbSchema();

        foreach ([$baseTable . $suffix, '_obsolete_' . $baseTable . $suffix] as $candidate) {
            if (!$dbSchema->hasTable($candidate)) {
                continue;
            }

            if (array_key_exists('Zone', $dbSchema->fieldList($candidate))) {
                return $candidate;
            }
        }

        $output->writeln(sprintf(
            '<comment>No Zone column found for %s%s — nothing to copy.</comment>',
            $baseTable,
            $suffix,
        ));

        return null;
    }

    private function copyStage(
        string $sourceTable,
        string $targetTable,
        string $suffix,
        bool $dryRun,
        PolyOutput $output,
    ): int {
        if (!$this->dbSchema()->hasTable($targetTable)) {
            return 0;
        }

        // _Versions rows carry their own surrogate ID; the record they describe
        // is identified by (RecordID, Version). Joining on ID there would pair
        // unrelated versions of unrelated elements.
        $join = $suffix === '_Versions'
            ? 's."RecordID" = t."RecordID" AND s."Version" = t."Version"'
            : 's."ID" = t."ID"';

        $where = '(t."Zone" IS NULL OR t."Zone" = \'\') AND s."Zone" IS NOT NULL AND s."Zone" != \'\'';

        $count = (int) DB::query(sprintf(
            'SELECT COUNT(*) FROM "%s" s INNER JOIN "%s" t ON %s WHERE %s',
            $sourceTable,
            $targetTable,
            $join,
            $where,
        ))->value();

        if ($count === 0) {
            return 0;
        }

        $output->writeln(sprintf(
            '%s %d row(s) from %s to %s',
            $dryRun ? 'Would copy' : 'Copying',
            $count,
            $sourceTable,
            $targetTable,
        ));

        if (!$dryRun) {
            DB::query(sprintf(
                'UPDATE "%s" t INNER JOIN "%s" s ON %s SET t."Zone" = s."Zone" WHERE %s',
                $targetTable,
                $sourceTable,
                $join,
                $where,
            ));
        }

        return $count;
    }
}
