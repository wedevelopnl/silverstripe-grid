<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\GridElement;

/**
 * Test extension for verifying updateElementFieldMapping hook.
 *
 * @extends Extension<GridMigrationService>
 */
final class TestMigrationExtension extends Extension
{
    public function updateElementFieldMapping(GridElement $element, LegacyElement $legacyElement): void
    {
        $element->Style = 'hook-applied';
    }
}
