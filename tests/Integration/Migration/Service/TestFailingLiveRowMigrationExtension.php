<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;

/**
 * Test extension whose live row hook throws when the legacy LIVE row's Title is
 * "FAIL_ME". The live pass runs after the page's draft hierarchy is written, so
 * this verifies the rollback also undoes that draft write.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\LivePublisher>
 */
final class TestFailingLiveRowMigrationExtension extends Extension
{
    public function updateLiveRowFieldMapping(Row $row, LegacyElement $liveRow): void
    {
        if ($liveRow->title === 'FAIL_ME') {
            throw new \RuntimeException('Deliberate live row hook failure');
        }
    }
}
