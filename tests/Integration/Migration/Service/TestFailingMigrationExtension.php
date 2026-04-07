<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\GridElement;

/**
 * Test extension that throws when an element's Title matches "FAIL_ME".
 *
 * Used to verify transaction rollback behaviour in migration tests.
 *
 * @extends Extension<GridMigrationService>
 */
final class TestFailingMigrationExtension extends Extension
{
    public function updateElementFieldMapping(GridElement $element, LegacyElement $legacyElement): void
    {
        if ($element->Title === 'FAIL_ME') {
            throw new \RuntimeException('Deliberate test failure');
        }
    }
}
