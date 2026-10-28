<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\ORM\DB;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * Raw-SQL access for the shared block placement repair's tests. They need the
 * state releases 6.0.0-beta.4 and beta.5 left behind — a placement whose block
 * record is gone — which the current delete no longer produces.
 */
trait StrandsSharedBlockPlacements
{
    private const string REFERENCE_TABLE = 'WeDevelop_Grid_SharedBlockReference';

    private const string ELEMENT_TABLE = 'WeDevelop_Grid_GridElement';

    /** Remove the block record from both stages, leaving its placements and subtree behind. */
    private function strand(SharedBlock $block): void
    {
        foreach (['', '_Live'] as $suffix) {
            DB::prepared_query(sprintf('DELETE FROM "WeDevelop_Grid_SharedBlock%s" WHERE "ID" = ?', $suffix), [$block->ID]);
        }
    }

    /** Remove one stage's rows of an element, as a draft delete without unpublishing leaves it on live alone. */
    private function removeFromStage(int $elementId, string $suffix): void
    {
        foreach ([self::REFERENCE_TABLE, self::ELEMENT_TABLE] as $table) {
            DB::prepared_query(sprintf('DELETE FROM "%s%s" WHERE "ID" = ?', $table, $suffix), [$elementId]);
        }
    }

    /** @return int<0, max> Rows of the element in $table on the stage $suffix names. */
    private function rowCount(string $table, int $elementId, string $suffix = ''): int
    {
        $key = $suffix === '_Versions' ? 'RecordID' : 'ID';

        /** @var int<0, max> */
        return (int) DB::prepared_query(
            sprintf('SELECT COUNT(*) FROM "%s%s" WHERE "%s" = ?', $table, $suffix, $key),
            [$elementId],
        )->value();
    }

    /** @return int<0, max> Rows of the element across both of its tables and all three stages. */
    private function storedRows(int $elementId): int
    {
        $count = 0;

        foreach ([self::REFERENCE_TABLE, self::ELEMENT_TABLE] as $table) {
            foreach (['', '_Live', '_Versions'] as $suffix) {
                $count += $this->rowCount($table, $elementId, $suffix);
            }
        }

        return $count;
    }

    /** @return list<array<string, mixed>> Every row of the grid element tables on the three stages. */
    private function snapshot(): array
    {
        $rows = [];

        foreach ([self::REFERENCE_TABLE, self::ELEMENT_TABLE] as $table) {
            foreach (['', '_Live', '_Versions'] as $suffix) {
                foreach (DB::query(sprintf('SELECT * FROM "%s%s" ORDER BY "ID"', $table, $suffix)) as $row) {
                    $rows[] = ['table' => $table . $suffix, ...$row];
                }
            }
        }

        return $rows;
    }
}
