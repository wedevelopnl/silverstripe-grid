<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class ConvertToSharedBlockRequest
{
    /** @param string $title Empty means "fall back to the element's own title". */
    public function __construct(
        public NodeRef $element,
        public string $title,
    ) {
    }
}
