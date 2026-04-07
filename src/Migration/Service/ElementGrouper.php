<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;

final class ElementGrouper
{
    /**
     * Split a flat sorted element list into groups delimited by ElementRow records.
     *
     * Elements before the first row form an implicit group (rowData: null).
     * Each row element starts a new group containing all following non-row elements
     * up to the next row (or end of list).
     *
     * @param list<LegacyElement> $elements Sorted by Sort ASC
     * @return list<array{rowData: ?LegacyRowData, elements: list<LegacyElement>}>
     */
    public function group(array $elements): array
    {
        $groups = [];
        $currentRowData = null;
        $currentElements = [];
        $hasSeenRow = false;

        foreach ($elements as $element) {
            if ($element->isRow) {
                // Flush the current group when we hit a row — but only if there's
                // something to flush (either a prior row started a group, or we
                // accumulated implicit elements before the first row).
                if ($hasSeenRow || $currentElements !== []) {
                    $groups[] = ['rowData' => $currentRowData, 'elements' => $currentElements];
                }

                $currentRowData = $element->rowData;
                $currentElements = [];
                $hasSeenRow = true;
            } else {
                $currentElements[] = $element;
            }
        }

        // Flush the final group (covers: implicit-only, last row's group, single row).
        if ($hasSeenRow || $currentElements !== []) {
            $groups[] = ['rowData' => $currentRowData, 'elements' => $currentElements];
        }

        return $groups;
    }
}
