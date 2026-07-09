<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Using {@see NodeRef} for element, parent, and after prevents the polymorphic
 * parent ID collision: a page ID and a section ID can share the same numeric
 * value, so the target parent must be identified by both type and id.
 */
final readonly class ReorderRequest
{
    public function __construct(
        public NodeRef $element,
        public NodeRef $parent,
        public ?NodeRef $after,
    ) {
    }
}
