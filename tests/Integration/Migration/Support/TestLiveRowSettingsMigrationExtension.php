<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * The live half of {@see TestRowSettingsMigrationExtension}, written the way the
 * documented contract requires: a targeted `_Live` UPDATE, never a write() on
 * the passed draft record.
 *
 * The method bodies are the examples of hooks 8 and 9 in docs/migration.md;
 * {@see \WeDevelop\Grid\Tests\Integration\Migration\MigrationDocsRowHookExamplesTest}
 * fails when the two drift apart.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\LivePublisher>
 */
final class TestLiveRowSettingsMigrationExtension extends Extension implements TestOnly
{
    public function updateLiveRowFieldMapping(Row $row, LegacyElement $liveRow): void
    {
        DB::prepared_query(
            'UPDATE "WeDevelop_Grid_Row_Live" SET "BackgroundColour" = ?, "RowImageID" = ? WHERE "ID" = ?',
            [
                (string) ($liveRow->extraData['BackgroundColour'] ?? ''),
                (int) ($liveRow->extraData['RowImageID'] ?? 0),
                $row->ID,
            ],
        );
    }

    public function updateLiveSectionFieldMapping(Section $section, LegacyElement $liveRow): void
    {
        DB::prepared_query(
            'UPDATE "WeDevelop_Grid_GridElement_Live" SET "Style" = ? WHERE "ID" = ?',
            [(bool) ($liveRow->extraData['IsCardRow'] ?? false) ? 'card' : '', $section->ID],
        );
    }
}
