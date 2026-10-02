<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Task\OneTime\Beta5;

/**
 * One GridElement row on one stage table, as {@see RepairGridZoneTask} reports
 * it. $rowId is the table's own primary key (a surrogate on the history
 * table); $elementId is the element the row describes.
 *
 * @internal
 */
final readonly class StageRow
{
    public function __construct(
        public int $rowId,
        public int $elementId,
        public ?int $version,
        public string $className,
        public string $parentClass,
        public int $parentId,
        public string $zone,
        public string $stage,
    ) {
    }

    public function label(): string
    {
        return sprintf(
            '%s #%d (%s)',
            $this->className,
            $this->elementId,
            $this->version === null ? $this->stage : sprintf('%s, version %d', $this->stage, $this->version),
        );
    }

    public function describe(string $reason): string
    {
        return sprintf(
            '%s on %s #%d: %s',
            $this->label(),
            $this->parentClass === '' ? '(no parent)' : $this->parentClass,
            $this->parentId,
            $reason,
        );
    }
}
