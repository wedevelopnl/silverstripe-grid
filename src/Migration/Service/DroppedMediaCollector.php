<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\MappedMediaFields;

/**
 * Collects, over one migration run, the legacy text blocks whose media settings
 * had no column to land in, so the run can report them once at the end.
 *
 * Keyed by legacy element ID: a block written on both stages, or in several
 * locale passes, counts once.
 */
final class DroppedMediaCollector
{
    /**
     * Legacy values that mean "nothing chosen" beyond null, '' and 0: the
     * `$defaults` ElementContentExtension wrote onto every new block.
     */
    private const array LEGACY_DEFAULTS = [
        'ContentVerticalAlign' => 'align-items-center',
        'MediaPosition' => 'order-1',
    ];

    /** @var array<int, array<non-empty-string, true>> legacy element ID → legacy columns left behind */
    private array $leftBehind = [];

    /**
     * @param list<non-empty-string> $droppedFields mapped field names no table holds
     */
    public function record(int $legacyElementId, LegacyMediaData $mediaData, array $droppedFields): void
    {
        foreach ($droppedFields as $field) {
            $column = MappedMediaFields::LEGACY_COLUMNS[$field] ?? null;
            if ($column !== null && $this->isChosen($column, $mediaData->fields[$column] ?? null)) {
                $this->leftBehind[$legacyElementId][$column] = true;
            }
        }
    }

    public function blockCount(): int
    {
        return \count($this->leftBehind);
    }

    /**
     * @return list<non-empty-string> legacy column names left behind, in legacy schema order
     */
    public function columns(): array
    {
        $left = \array_merge([], ...\array_values($this->leftBehind));

        return \array_values(\array_filter(
            MappedMediaFields::LEGACY_COLUMNS,
            static fn (string $column): bool => isset($left[$column]),
        ));
    }

    private function isChosen(string $column, mixed $value): bool
    {
        return !\in_array($value, [null, '', 0, '0'], true)
            && $value !== (self::LEGACY_DEFAULTS[$column] ?? null);
    }
}
