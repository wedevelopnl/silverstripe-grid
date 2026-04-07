<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;

/**
 * Test extension on LegacyDataReader that enriches elements with custom
 * subclass data from a legacy 'LegacyHeroBlock' table.
 *
 * Simulates what a real project would do: read custom fields from their
 * old element subclass table and attach them to extraData.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\LegacyDataReader>
 */
final class TestCustomElementReaderExtension extends Extension
{
    private const string HERO_CLASS = 'App\\Elements\\HeroBlock';

    /**
     * @param list<LegacyElement> $elements
     */
    public function updateLegacyElements(array &$elements, int $areaId, string $stage): void
    {
        $table = \strtolower($stage) === 'live' ? 'LegacyHeroBlock_Live' : 'LegacyHeroBlock';

        $enriched = [];
        foreach ($elements as $element) {
            if ($element->className === self::HERO_CLASS) {
                $result = DB::prepared_query(
                    \sprintf('SELECT "Subtitle", "ButtonText" FROM "%s" WHERE "ID" = ?', $table),
                    [$element->id],
                );

                if ($result->numRecords() > 0) {
                    /** @var array<string, string|null> $row */
                    $row = $result->record();
                    $element = new LegacyElement(
                        id: $element->id,
                        className: $element->className,
                        title: $element->title,
                        showTitle: $element->showTitle,
                        titleTag: $element->titleTag,
                        titleClass: $element->titleClass,
                        sort: $element->sort,
                        extraClass: $element->extraClass,
                        isRow: $element->isRow,
                        sizeFields: $element->sizeFields,
                        offsetFields: $element->offsetFields,
                        visibilityFields: $element->visibilityFields,
                        rowData: $element->rowData,
                        mediaData: $element->mediaData,
                        extraData: [
                            'Subtitle' => $row['Subtitle'] ?? '',
                            'ButtonText' => $row['ButtonText'] ?? '',
                        ],
                    );
                }
            }
            $enriched[] = $element;
        }
        $elements = $enriched;
    }
}
