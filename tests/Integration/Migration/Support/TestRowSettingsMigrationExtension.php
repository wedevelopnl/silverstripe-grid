<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * A project's draft row and section mapping: the legacy row's background colour
 * and row image onto the Row, its "card row" switch onto the Section's style.
 *
 * The method bodies are the examples of hooks 6 and 7 in docs/migration.md;
 * {@see \WeDevelop\Grid\Tests\Integration\Migration\MigrationDocsRowHookExamplesTest}
 * fails when the two drift apart.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\DraftHierarchyWriter>
 */
final class TestRowSettingsMigrationExtension extends Extension implements TestOnly
{
    public function updateRowFieldMapping(Row $row, LegacyElement $legacyRow): void
    {
        $row->BackgroundColour = (string) ($legacyRow->extraData['BackgroundColour'] ?? '');
        // A has_one copies as its ID. Publishing the file is the project's job.
        $row->RowImageID = (int) ($legacyRow->extraData['RowImageID'] ?? 0);
    }

    public function updateSectionFieldMapping(Section $section, LegacyElement $legacyRow): void
    {
        $section->Style = (bool) ($legacyRow->extraData['IsCardRow'] ?? false) ? 'card' : '';
    }
}
