<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;

/**
 * Test extension for verifying updateClassNameMapping hook.
 *
 * Set {@see $targetClass} before registering to control the mapping target.
 * Set {@see $sourceClass} to restrict which old class names are remapped
 * (null matches all).
 *
 * @extends Extension<GridMigrationService>
 */
final class TestClassNameMappingExtension extends Extension
{
    /** @var class-string The class to remap to. */
    public static string $targetClass = '';

    /** @var string|null When set, only remap this specific old class name. */
    public static ?string $sourceClass = null;

    /**
     * Remap class names for testing.
     *
     * The hook receives $newClassName by reference so modifications propagate.
     */
    public function updateClassNameMapping(string &$newClassName, string $oldClassName): void
    {
        if (self::$sourceClass !== null && $oldClassName !== self::$sourceClass) {
            return;
        }

        $newClassName = self::$targetClass;
    }
}
