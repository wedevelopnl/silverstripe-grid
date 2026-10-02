<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\ORM\DB;

/**
 * Raw-SQL access to GridElement's Zone column on each versioned stage table,
 * for the one-time Zone tasks' tests. They manufacture states the ORM refuses
 * to write — legacy tables, blank zones on page roots — so they bypass it.
 *
 * Call {@see dropSeededTables()} from tearDown().
 */
trait ManipulatesGridZoneTables
{
    private const string GRID_ELEMENT_TABLE = 'WeDevelop_Grid_GridElement';

    /** @var list<string> */
    private array $droppableTables = [];

    /**
     * The shape left behind when a vacated subclass table is renamed whole.
     *
     * @param array<int, string> $zonesById
     */
    private function seedObsoleteTable(string $table, array $zonesById): void
    {
        DB::query(sprintf('DROP TABLE IF EXISTS "%s"', $table));
        DB::query(sprintf('CREATE TABLE "%s" ("ID" INT NOT NULL, "Zone" VARCHAR(50), PRIMARY KEY ("ID"))', $table));
        $this->droppableTables[] = $table;

        foreach ($zonesById as $id => $zone) {
            DB::prepared_query(sprintf('INSERT INTO "%s" ("ID", "Zone") VALUES (?, ?)', $table), [$id, $zone]);
        }
    }

    private function dropSeededTables(): void
    {
        foreach ($this->droppableTables as $table) {
            DB::query(sprintf('DROP TABLE IF EXISTS "%s"', $table));
        }
        $this->droppableTables = [];
    }

    /** @return list<array{Version: int}> */
    private function versionRowsFor(int $elementId): array
    {
        $rows = [];
        $query = DB::prepared_query(
            sprintf('SELECT "Version" FROM "%s_Versions" WHERE "RecordID" = ? ORDER BY "Version" ASC', self::GRID_ELEMENT_TABLE),
            [$elementId],
        );

        foreach ($query as $row) {
            $rows[] = ['Version' => (int) $row['Version']];
        }

        return $rows;
    }

    private function versionZoneOf(int $elementId, int $version): string
    {
        return (string) DB::prepared_query(
            sprintf('SELECT "Zone" FROM "%s_Versions" WHERE "RecordID" = ? AND "Version" = ?', self::GRID_ELEMENT_TABLE),
            [$elementId, $version],
        )->value();
    }

    /** @param '' | '_Live' $suffix */
    private function zoneOf(int $elementId, string $suffix = ''): string
    {
        return (string) DB::prepared_query(
            sprintf('SELECT "Zone" FROM "%s%s" WHERE "ID" = ?', self::GRID_ELEMENT_TABLE, $suffix),
            [$elementId],
        )->value();
    }

    /** @param '' | '_Live' $suffix */
    private function sortOf(int $elementId, string $suffix = ''): int
    {
        return (int) DB::prepared_query(
            sprintf('SELECT "Sort" FROM "%s%s" WHERE "ID" = ?', self::GRID_ELEMENT_TABLE, $suffix),
            [$elementId],
        )->value();
    }

    /**
     * Overwrites the Zone of an element's row on one stage table, or of every
     * one of its history rows for '_Versions'.
     *
     * @param '' | '_Live' | '_Versions' $suffix
     */
    private function setZone(int $elementId, ?string $zone, string $suffix = ''): void
    {
        DB::prepared_query(
            sprintf(
                'UPDATE "%s%s" SET "Zone" = ? WHERE "%s" = ?',
                self::GRID_ELEMENT_TABLE,
                $suffix,
                $suffix === '_Versions' ? 'RecordID' : 'ID',
            ),
            [$zone, $elementId],
        );
    }
}
