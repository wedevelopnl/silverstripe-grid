<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Repository;

use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\NodeRef;

interface GridElementRepositoryInterface
{
    /**
     * @param positive-int $id
     *
     * @deprecated 6.0.0 Use {@see findByRef()}. Unlike this method it pins the
     *     DRAFT stage and is scoped by NodeType, so it cannot resolve the wrong
     *     record when a page ID and an element ID collide numerically. Will be
     *     removed in 7.0.0.
     */
    public function findById(int $id): ?GridElement;

    /**
     * Find an element by its scoped {@see NodeRef}.
     *
     * Returns null when the ref refers to a non-element type (e.g. Page),
     * or when the type/id combination doesn't correspond to a record the
     * caller can view at the current stage.
     */
    public function findByRef(NodeRef $ref): ?GridElement;

    /**
     * Find all elements belonging to the given parent IDs and class, ordered by Sort ASC, ID ASC.
     *
     * @param list<positive-int> $parentIds
     * @param class-string $parentClass
     * @return list<GridElement>
     */
    public function findByParentIds(array $parentIds, string $parentClass): array;

    /**
     * Find all elements matching the given parent ID+class pairs, ordered by Sort ASC, ID ASC.
     *
     * Uses both ParentID and ParentClass to avoid false matches when IDs from
     * different tables (e.g. SiteTree and GridElement) collide.
     *
     * When $zone is provided, an additional Zone filter is applied (root-level queries only).
     *
     * @param array<class-string, list<positive-int>> $idsByClass Map of parent class → parent IDs
     * @return list<GridElement>
     */
    public function findByParents(array $idsByClass, ?string $zone = null): array;
}
