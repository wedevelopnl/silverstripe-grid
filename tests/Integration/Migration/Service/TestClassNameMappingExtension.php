<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Model\ContentElement;

/**
 * Test extension for verifying updateClassNameMapping hook.
 *
 * @extends Extension<GridMigrationService>
 */
final class TestClassNameMappingExtension extends Extension
{
    /**
     * Remap all class names to ContentElement for testing.
     *
     * The hook receives $newClassName by reference so modifications propagate.
     */
    public function updateClassNameMapping(string &$newClassName, string $oldClassName): void
    {
        // Override to ContentElement regardless of input
        $newClassName = ContentElement::class;
    }
}
