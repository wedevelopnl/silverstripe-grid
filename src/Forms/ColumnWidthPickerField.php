<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use SilverStripe\Core\Manifest\ModuleResource;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Radio button field that renders column width layout images as radio options.
 *
 * Each option shows a PNG diagram of the content/media column split.
 * Falls back to CSS-drawn bars when no image exists for the given split.
 */
final class ColumnWidthPickerField extends OptionsetField
{
    private const string IMAGE_BASE = 'wedevelopnl/silverstripe-grid:client/images/alignments/';

    /**
     * @param array<int, string> $source
     * @param int<1, max> $totalColumns
     */
    public function __construct(string $name, ?string $title, array $source, private readonly int $totalColumns)
    {
        parent::__construct($name, $title, $source);
    }

    /** @return int<1, max> */
    public function getTotalColumns(): int
    {
        return $this->totalColumns;
    }

    /**
     * Build option data with image URLs for layout diagrams.
     *
     * @return ArrayList<ArrayData>
     */
    public function getPickerOptions(): ArrayList
    {
        /** @var array<int|string, string> $source */
        $source = $this->getSource();
        /** @var int|string|null $currentValue */
        $currentValue = $this->getValue();
        $list = ArrayList::create();

        foreach ($source as $optionValue => $optionTitle) {
            $isFullWidth = $optionValue === 0;
            $mediaColumns = $this->totalColumns - (int) $optionValue;
            $imageUrl = $this->resolveImageUrl($isFullWidth, (int) $optionValue, $mediaColumns);
            $contentPercent = $isFullWidth ? 100.0 : round((int) $optionValue / $this->totalColumns * 100, 1);

            $list->push(ArrayData::create([
                'Value' => $optionValue,
                'Title' => $optionTitle,
                'isChecked' => (string) $optionValue === (string) $currentValue,
                'ImageURL' => $imageUrl,
                'ContentPercent' => $contentPercent,
                'MediaPercent' => round(100 - $contentPercent, 1),
                'MediaColumns' => $mediaColumns,
            ]));
        }

        return $list;
    }

    /**
     * Resolve the public URL for a column split image, or empty string if missing.
     */
    private function resolveImageUrl(bool $isFullWidth, int $contentColumns, int $mediaColumns): string
    {
        $filename = $isFullWidth ? 'vertical.png' : sprintf('horizontal_%d-%d.png', $contentColumns, $mediaColumns);

        $resourcePath = self::IMAGE_BASE . $filename;
        $loader = ModuleResourceLoader::singleton();

        /** @var ModuleResource|string $resource */
        $resource = $loader->resolveResource($resourcePath);

        if (!$resource instanceof ModuleResource || !$resource->exists()) {
            return '';
        }

        return (string) $loader->resolveURL($resourcePath);
    }
}
