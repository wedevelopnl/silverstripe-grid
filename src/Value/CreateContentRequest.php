<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use WeDevelop\Grid\Model\ContentElement;

final readonly class CreateContentRequest
{
    /**
     * @param class-string<ContentElement> $className
     * @param positive-int $parentId
     * @param positive-int|null $insertAfterElementID
     */
    public function __construct(
        public string $className,
        public int $parentId,
        public ?int $insertAfterElementID,
    ) {
    }
}
