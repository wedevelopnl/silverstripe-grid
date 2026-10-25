<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;

/**
 * Test extension whose draft row hook throws when the legacy row's Title is
 * "FAIL_ME", to verify a failing row mapping rolls its page back.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\DraftHierarchyWriter>
 */
final class TestFailingRowMigrationExtension extends Extension
{
    public function updateRowFieldMapping(Row $row, LegacyElement $legacyRow): void
    {
        if ($legacyRow->title === 'FAIL_ME') {
            throw new \RuntimeException('Deliberate row hook failure');
        }
    }
}
