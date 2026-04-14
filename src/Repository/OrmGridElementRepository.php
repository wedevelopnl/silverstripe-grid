<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Repository;

use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;

final class OrmGridElementRepository implements GridElementRepositoryInterface
{
    public function findById(int $id): ?GridElement
    {
        /** @var GridElement|null */
        return GridElement::get()->byID($id);
    }

    public function findByParentIds(array $parentIds, string $parentClass): array
    {
        if ($parentIds === []) {
            return [];
        }

        /** @var list<GridElement> */
        return GridElement::get()
            ->filter([
                'ParentID' => $parentIds,
                'ParentClass' => $parentClass,
            ])
            ->sort(['Sort' => 'ASC', 'ID' => 'ASC'])
            ->toArray();
    }

    public function findByParents(array $idsByClass, ?string $zone = null): array
    {
        if ($idsByClass === []) {
            return [];
        }

        // Query once per class so (ParentClass, ParentID) stays pair-matched.
        // A flat IN-list would produce the cartesian product across classes, matching
        // unrelated records whenever IDs collide across tables (polymorphic namespace).
        $merged = [];
        foreach ($idsByClass as $class => $ids) {
            if ($ids === []) {
                continue;
            }

            $filter = [
                'ParentClass' => $class,
                'ParentID' => $ids,
            ];

            if ($zone !== null) {
                $filter['Zone'] = $zone;
                $list = Section::get();
            } else {
                $list = GridElement::get();
            }

            foreach ($list->filter($filter) as $element) {
                $merged[] = $element;
            }
        }

        usort(
            $merged,
            static fn (GridElement $a, GridElement $b): int
                => [(int) $a->Sort, (int) $a->ID] <=> [(int) $b->Sort, (int) $b->ID],
        );

        /** @var list<GridElement> */
        return $merged;
    }
}
