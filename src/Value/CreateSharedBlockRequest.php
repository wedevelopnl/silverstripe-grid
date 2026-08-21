<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use WeDevelop\Grid\Model\GridElement;

/**
 * A new library block and the class of the single root it is seeded with.
 *
 * The wire body names either a container type or a leaf class; both collapse to
 * one class here, since the block's root is judged by its class everywhere else
 * (placement matrix, library filtering, effective-class resolution).
 */
final readonly class CreateSharedBlockRequest
{
    /**
     * @param class-string<GridElement> $rootClass Section, Row, Column, or any
     *   element class a Column can hold — the four shapes a block may root.
     */
    public function __construct(
        public string $rootClass,
    ) {
    }
}
