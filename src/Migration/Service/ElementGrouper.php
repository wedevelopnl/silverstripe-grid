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
     * Elements before the first row form an implicit group (row: null, rowData: null).
     * Each row element starts a new group containing all following non-row elements
     * up to the next row (or end of list).
     *
     * @param list<LegacyElement> $elements Sorted by Sort ASC
     * @return list<array{row: ?LegacyElement, rowData: ?LegacyRowData, elements: list<LegacyElement>}>
     */
    public function group(array $elements): array
    {
        if ($elements === []) {
            return [];
        }

        // The first element determines the initial context: if it's a row we
        // start in that row's group, otherwise we start in an implicit group.
        $first = $elements[0];
        $currentRow = $first->isRow ? $first : null;
        $currentRowData = $first->isRow ? $first->rowData : null;
        $currentElements = $first->isRow ? [] : [$first];

        $groups = [];

        for ($i = 1, $count = \count($elements); $i < $count; $i++) {
            $element = $elements[$i];
            if ($element->isRow) {
                $groups[] = ['row' => $currentRow, 'rowData' => $currentRowData, 'elements' => $currentElements];
                $currentRow = $element;
                $currentRowData = $element->rowData;
                $currentElements = [];
            } else {
                $currentElements[] = $element;
            }
        }

        $groups[] = ['row' => $currentRow, 'rowData' => $currentRowData, 'elements' => $currentElements];

        return $groups;
    }
}
