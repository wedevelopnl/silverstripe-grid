<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use WeDevelop\Grid\Model\GridElement;

final readonly class CreateContentRequest
{
    /**
     * @param class-string<GridElement> $className A concrete non-container element
     *   type — `ContentElement` subclasses and direct `GridElement` subclasses alike.
     * @param positive-int|null $insertAfterElementID
     */
    public function __construct(
        public string $className,
        public NodeRef $parent,
        public ?int $insertAfterElementID,
    ) {
    }
}
