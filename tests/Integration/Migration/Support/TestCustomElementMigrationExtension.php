<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestCustomElement;

/**
 * Test extension on GridMigrationService that handles custom element
 * ClassName mapping and field migration via extraData.
 *
 * Simulates what a real project would do: map their old element class
 * to the new one, and copy custom fields from extraData to the new record.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\GridMigrationService>
 */
final class TestCustomElementMigrationExtension extends Extension
{
    private const string OLD_CLASS = 'App\\Elements\\HeroBlock';

    public function updateClassNameMapping(string &$newClassName, string $oldClassName): void
    {
        if ($oldClassName === self::OLD_CLASS) {
            $newClassName = TestCustomElement::class;
        }
    }

    public function updateElementFieldMapping(GridElement $element, LegacyElement $legacyElement): void
    {
        if (!$element instanceof TestCustomElement) {
            return;
        }

        $element->Subtitle = (string) ($legacyElement->extraData['Subtitle'] ?? '');
        $element->ButtonText = (string) ($legacyElement->extraData['ButtonText'] ?? '');
    }
}
