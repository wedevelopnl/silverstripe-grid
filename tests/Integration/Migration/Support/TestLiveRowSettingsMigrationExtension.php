<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * The live half of {@see TestRowSettingsMigrationExtension}, written the way the
 * documented contract requires: a targeted `_Live` UPDATE, never a write() on
 * the passed draft record.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\LivePublisher>
 */
final class TestLiveRowSettingsMigrationExtension extends Extension implements TestOnly
{
    /** @var list<string> one `<Class>:<ID>` entry per fired hook, so a test can see one did NOT fire */
    public static array $fired = [];

    public function updateLiveRowFieldMapping(Row $row, LegacyElement $liveRow): void
    {
        self::$fired[] = 'Row:' . $row->ID;
        DB::prepared_query(
            \sprintf(
                'UPDATE "%s_Live" SET "BackgroundColour" = ? WHERE "ID" = ?',
                DataObject::getSchema()->tableForField(Row::class, 'BackgroundColour'),
            ),
            [(string) ($liveRow->extraData['BackgroundColour'] ?? ''), $row->ID],
        );
    }

    public function updateLiveSectionFieldMapping(Section $section, LegacyElement $liveRow): void
    {
        self::$fired[] = 'Section:' . $section->ID;
        DB::prepared_query(
            \sprintf(
                'UPDATE "%s_Live" SET "Style" = ? WHERE "ID" = ?',
                DataObject::getSchema()->tableForField(Section::class, 'Style'),
            ),
            [(bool) ($liveRow->extraData['IsCardRow'] ?? false) ? 'card' : '', $section->ID],
        );
    }
}
