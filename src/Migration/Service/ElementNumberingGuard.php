<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use RuntimeException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Model\GridElement;

/**
 * Makes new grid elements number above every legacy element ID.
 *
 * Grid elements draw their IDs from their own counter, which overlaps the
 * legacy element IDs. An item a project re-attaches by "parent = legacy ID"
 * would then also match a block an earlier page already migrated under that
 * number. Raised above the highest legacy ID, an item still pointing at a
 * legacy number has not been moved yet.
 *
 * Run once, before the first page of a real (not dry) run, outside the page
 * transactions. MySQL only: PostgreSQL sequences are not raised.
 */
final class ElementNumberingGuard
{
    /**
     * @throws RuntimeException when a legacy element table is missing
     */
    public function raiseAboveLegacyIds(): void
    {
        $highest = $this->highestLegacyId();
        $table = DataObject::getSchema()->baseDataTable(GridElement::class);

        if ($highest === 0 || $this->hasRow($table, $highest)) {
            return;
        }

        // MySQL moves an AUTO_INCREMENT counter past any ID inserted explicitly
        // and never back, also when the row is deleted or rolled back. Unlike
        // ALTER TABLE ... AUTO_INCREMENT this is no DDL, so it never commits an
        // open transaction implicitly.
        DB::prepared_query(\sprintf('INSERT INTO "%s" ("ID") VALUES (?)', $table), [$highest]);
        DB::prepared_query(\sprintf('DELETE FROM "%s" WHERE "ID" = ?', $table), [$highest]);
    }

    /**
     * The highest ID a legacy element ever had: on draft, on live, or only in
     * the version history of an archived element.
     */
    private function highestLegacyId(): int
    {
        $element = LegacyElementReader::ELEMENT_TABLE;
        $sources = [$element => 'ID', $element . '_Live' => 'ID', $element . '_Versions' => 'RecordID'];

        $missing = \array_values(\array_filter(
            \array_keys($sources),
            static fn (string $table): bool => !LegacyTables::exists($table),
        ));
        if ($missing !== []) {
            throw new RuntimeException(\sprintf(
                'Legacy element %s "%s" not found; new grid elements cannot be numbered above the legacy ones. '
                . 'Restore the legacy tables before migrating; nothing was changed.',
                \count($missing) === 1 ? 'table' : 'tables',
                \implode('", "', $missing),
            ));
        }

        $highest = 0;
        foreach ($sources as $table => $column) {
            $highest = \max($highest, (int) DB::query(\sprintf('SELECT MAX("%s") FROM "%s"', $column, $table))->value());
        }

        return $highest;
    }

    private function hasRow(string $table, int $id): bool
    {
        return DB::prepared_query(\sprintf('SELECT 1 FROM "%s" WHERE "ID" = ?', $table), [$id])->numRecords() > 0;
    }
}
