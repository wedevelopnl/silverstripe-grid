<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Task\OneTime\Beta5;

use SilverStripe\ORM\DB;

/**
 * Raw access to one versioned stage table of GridElement for
 * {@see RepairGridZoneTask}.
 *
 * Exists because the history table identifies a record differently: its rows
 * carry their own surrogate ID, and the element they describe is
 * (RecordID, Version). Every query here hides that difference.
 *
 * @internal
 */
final readonly class StageTable
{
    public function __construct(
        private string $name,
        private bool $isVersions,
        private string $stage,
    ) {
    }

    /**
     * Rows matching $where, ordered per parent by Sort and then identity —
     * the order a zone renders its roots in.
     *
     * @param list<string> $params
     * @return list<StageRow>
     */
    public function select(string $where, array $params): array
    {
        $identity = $this->isVersions ? '"RecordID" AS "ElementID", "Version"' : '"ID" AS "ElementID", NULL AS "Version"';
        $order = $this->isVersions ? '"RecordID", "Version"' : '"ID"';

        $query = DB::prepared_query(
            sprintf(
                'SELECT "ID", %s, "ClassName", "ParentClass", "ParentID", "Zone" FROM "%s" WHERE %s '
                . 'ORDER BY "ParentClass", "ParentID", "Sort", %s',
                $identity,
                $this->name,
                $where,
                $order,
            ),
            $params,
        );

        $rows = [];
        /** @var array{ID: int|string, ElementID: int|string, Version: int|string|null, ClassName: string, ParentClass: string|null, ParentID: int|string, Zone: string|null} $record */
        foreach ($query as $record) {
            $rows[] = new StageRow(
                rowId: (int) $record['ID'],
                elementId: (int) $record['ElementID'],
                version: $record['Version'] === null ? null : (int) $record['Version'],
                className: (string) $record['ClassName'],
                parentClass: (string) $record['ParentClass'],
                parentId: (int) $record['ParentID'],
                zone: (string) $record['Zone'],
                stage: $this->stage,
            );
        }

        return $rows;
    }

    public function maxSort(string $parentClass, int $parentId, string $zone): int
    {
        return (int) DB::prepared_query(
            sprintf('SELECT MAX("Sort") FROM "%s" WHERE "ParentClass" = ? AND "ParentID" = ? AND "Zone" = ?', $this->name),
            [$parentClass, $parentId, $zone],
        )->value();
    }

    /** A null $sort leaves Sort as it is. */
    public function update(StageRow $row, ?string $zone, ?int $sort): void
    {
        if ($sort === null) {
            DB::prepared_query(sprintf('UPDATE "%s" SET "Zone" = ? WHERE "ID" = ?', $this->name), [$zone, $row->rowId]);

            return;
        }

        DB::prepared_query(
            sprintf('UPDATE "%s" SET "Zone" = ?, "Sort" = ? WHERE "ID" = ?', $this->name),
            [$zone, $sort, $row->rowId],
        );
    }
}
