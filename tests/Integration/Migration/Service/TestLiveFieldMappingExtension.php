<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\GridElement;

/**
 * Test extension for verifying the updateLiveElementFieldMapping hook.
 *
 * Written the way the documented contract requires: a targeted `_Live` UPDATE,
 * never a write() on the passed draft record.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\LivePublisher>
 */
final class TestLiveFieldMappingExtension extends Extension
{
    public const string MARKER = 'live-hook-applied';

    public function updateLiveElementFieldMapping(GridElement $element, LegacyElement $liveElement): void
    {
        DB::prepared_query(
            \sprintf(
                'UPDATE "%s_Live" SET "ExtraClass" = ? WHERE "ID" = ?',
                DataObject::getSchema()->tableName(GridElement::class),
            ),
            [self::MARKER, $element->ID],
        );
    }
}
