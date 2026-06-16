<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\KeysForCache;

use WeDevelop\Grid\Model\GridElement;

trait ReadsCacheKeys
{
    /**
     * Re-reads each element so no in-memory state answers for the database.
     *
     * @param array<string, GridElement> $elements
     * @return array<string, string>
     */
    private static function freshKeysOf(array $elements): array
    {
        return array_map(
            static fn (GridElement $element): string => (string) GridElement::get()->byID($element->ID)?->getCacheKey(),
            $elements,
        );
    }
}
