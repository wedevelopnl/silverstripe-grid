<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Forms\GridEditorField;

/**
 * The grid zones a page declares: one per GridEditorField in its CMS form.
 *
 * Zones are not configuration — a page type declares them by adding editor
 * fields in getCMSFields(), and a project may add or drop them per page (the
 * UseGrid toggle removes the editor altogether). Building the form is the only
 * way to read that answer, so it is expensive: call it per page, not per row.
 */
class GridZoneResolver
{
    use Injectable;

    /** @return list<non-empty-string> In form order, each zone once. */
    public function zonesFor(SiteTree $page): array
    {
        $zones = [];
        foreach ($page->getCMSFields()->flattenFields() as $field) {
            if ($field instanceof GridEditorField) {
                $zones[] = $field->getZone();
            }
        }

        /** @var list<non-empty-string> */
        return array_values(array_unique($zones));
    }
}
