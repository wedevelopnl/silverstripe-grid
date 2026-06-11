<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Repository;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;

final class OrmGridElementRepository implements GridElementRepositoryInterface
{
    public function findById(int $id): ?GridElement
    {
        /** @var GridElement|null */
        return GridElement::get()->byID($id);
    }

    public function findByRef(NodeRef $ref): ?GridElement
    {
        if ($ref->type === NodeType::Page) {
            return null;
        }

        /** @var class-string<GridElement> $class */
        $class = $ref->type->toClass();
        $id = $ref->id;

        // Pin the DRAFT stage so mutation-endpoint lookups resolve the editable
        // record regardless of the ambient reading stage, mirroring
        // GridController::resolveNodeRef and the GET read endpoints. Without
        // this, a request whose ambient stage is LIVE would fail to find a
        // DRAFT-only element and the controller would respond 404/400.
        /** @var GridElement|null $record */
        $record = Versioned::withVersionedMode(static function () use ($class, $id): ?GridElement {
            Versioned::set_stage(Versioned::DRAFT);

            /** @var GridElement|null $found */
            $found = DataObject::get($class)->byID($id);

            return $found;
        });

        if ($record === null) {
            return null;
        }

        // Guard against a polymorphic collision: if a caller passes a Row NodeRef
        // but the numeric ID happens to also exist in a different GridElement
        // subclass, byID on the narrow base class still returns the matching
        // record. Verify the fetched record actually is the expected class.
        return $record instanceof $class ? $record : null;
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

            // The Zone filter only applies to root-level sections (parented to a
            // page). Branch on the parent class — not merely on whether a zone
            // was passed — so a stray zone on a non-page parent never silently
            // swaps the query base to Section::get(). Child elements (rows,
            // columns, content) are scoped by their container parent and carry
            // no zone of their own.
            if ($zone !== null && is_a($class, SiteTree::class, true)) {
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

        return $merged;
    }
}
