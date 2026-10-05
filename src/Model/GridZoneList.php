<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\Model\List\ArrayList;

/**
 * The root elements of one page zone, in Sort order.
 *
 * Renders itself, so a template writes `$GridZone('main')` rather than looping
 * a list whose body would only be `$Me`. It stays a list, so
 * `<% loop $GridZone('main') %>` still works where each root needs its own
 * wrapper, and `<% if $GridZone('main') %>` tests for an empty zone.
 *
 * @extends ArrayList<GridElement>
 */
class GridZoneList extends ArrayList
{
    #[Override]
    public function forTemplate(): string
    {
        return implode('', array_map(
            static fn (GridElement $root): string => $root->forTemplate(),
            $this->toArray(),
        ));
    }
}
