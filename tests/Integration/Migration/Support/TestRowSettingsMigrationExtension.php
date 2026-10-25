<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * A project's draft row and section mapping, written the way docs/migration.md
 * shows it: the legacy row's background colour onto the Row, its "card row"
 * switch onto the Section's style.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\DraftHierarchyWriter>
 */
final class TestRowSettingsMigrationExtension extends Extension implements TestOnly
{
    public function updateRowFieldMapping(Row $row, LegacyElement $legacyRow): void
    {
        $row->setField('BackgroundColour', (string) ($legacyRow->extraData['BackgroundColour'] ?? ''));
    }

    public function updateSectionFieldMapping(Section $section, LegacyElement $legacyRow): void
    {
        $section->Style = (bool) ($legacyRow->extraData['IsCardRow'] ?? false) ? 'card' : '';
    }
}
