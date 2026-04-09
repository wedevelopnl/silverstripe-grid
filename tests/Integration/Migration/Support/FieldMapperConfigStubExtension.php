<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;

/**
 * Test-only extension for `AbstractMigrationTask::updateFieldMapperConfig`.
 *
 * Replaces the vertical-alignment lookup with a single non-Bootstrap entry so
 * tests can assert the override reaches the constructed `FieldMapper`. The
 * other three maps are left untouched (null), which preserves FieldMapper's
 * built-in defaults for everything the test does not care about.
 */
final class FieldMapperConfigStubExtension extends Extension
{
    public const string CUSTOM_ALIGN_INPUT = 'stub-centered';

    /**
     * @param array<string, string>|null $classNameMap
     * @param array<string, string>|null $verticalAlignMap
     * @param array<string, string>|null $mediaPositionMap
     * @param array<int, int>|null       $gapSizeMap
     */
    public function updateFieldMapperConfig(
        ?array &$classNameMap,
        ?array &$verticalAlignMap,
        ?array &$mediaPositionMap,
        ?array &$gapSizeMap,
    ): void {
        $verticalAlignMap = [
            self::CUSTOM_ALIGN_INPUT => 'bottom',
        ];
    }
}
