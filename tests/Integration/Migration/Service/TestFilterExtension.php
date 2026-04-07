<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;

/**
 * Test extension that filters out legacy elements by title.
 *
 * Removes any element with title "Skip Me" from the migration input.
 *
 * @extends Extension<LegacyDataReader>
 */
final class TestFilterExtension extends Extension
{
    /**
     * @param list<LegacyElement> $elements
     */
    public function updateLegacyElements(array &$elements, int $areaId, string $stage): void
    {
        $elements = \array_values(\array_filter(
            $elements,
            static fn (LegacyElement $el): bool => $el->title !== 'Skip Me',
        ));
    }
}
