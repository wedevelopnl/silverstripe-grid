<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;

/**
 * Test extension on LegacyDataReader that verifies the updateLegacyElements
 * hook is called with correct parameters and can mutate the elements array.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\LegacyDataReader>
 */
final class TestLegacyReaderFilterExtension extends Extension
{
    public static bool $hookCalled = false;

    public static int $receivedAreaId = 0;

    public static string $receivedStage = '';

    public static function reset(): void
    {
        self::$hookCalled = false;
        self::$receivedAreaId = 0;
        self::$receivedStage = '';
    }

    /**
     * @param list<LegacyElement> $elements
     */
    public function updateLegacyElements(array &$elements, int $areaId, string $stage): void
    {
        self::$hookCalled = true;
        self::$receivedAreaId = $areaId;
        self::$receivedStage = $stage;

        $elements = \array_values(\array_filter(
            $elements,
            static fn (LegacyElement $el): bool => $el->title !== 'Filter Me',
        ));
    }
}
