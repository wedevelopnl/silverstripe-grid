<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use Override;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DataObjectInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Per-viewport grid settings field for Column elements.
 *
 * Uses a default-viewport-anchored model: the adapter's default viewport is
 * the primary configuration; other viewports store independent overrides.
 * Handles the full lifecycle: JSON decode on load, per-viewport controls
 * in the template, JSON encode on save.
 */
class GridSettingsField extends FormField
{
    private GridSettings $gridSettings;

    public function __construct(string $name, private readonly GridAdapterInterface $adapter, ?string $title = null)
    {
        $this->gridSettings = GridSettings::initial($this->adapter->getColumnCount());

        parent::__construct($name, $title ?? 'Grid Settings');
    }

    /**
     * Accept value from DB (JSON string) or form submission (nested array).
     *
     * @param array<string, mixed>|ModelData|null $data
     */
    #[Override]
    public function setValue(mixed $value, mixed $data = null): static
    {
        if ($value instanceof GridSettings) {
            $this->gridSettings = $value;
        } elseif (is_array($value)) {
            /** @var array<string, mixed> $value */
            $this->gridSettings = $this->normalizeFormData($value);
        }

        return parent::setValue($value, $data);
    }

    /**
     * Write grid settings into the record via the composite DB field.
     */
    #[Override]
    public function saveInto(DataObjectInterface $record): void
    {
        $record->setCastedField($this->name, $this->gridSettings);
    }

    /**
     * Viewport data for the template, as an ArrayList of ArrayData.
     *
     * @return ArrayList<ArrayData>
     */
    public function getViewportData(): ArrayList
    {
        $list = ArrayList::create();
        $viewports = $this->adapter->getViewports();
        $columnCount = $this->adapter->getColumnCount();
        $defaultKey = $this->adapter->getDefaultViewport()->key;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $isDefault = $key === $defaultKey;
            $hasOverride = $this->gridSettings->hasOverride($key);
            $config = $this->gridSettings->forViewport($key);

            $list->push(ArrayData::create([
                'Key' => $key,
                'Label' => $viewport->label,
                'Width' => $config->width,
                'Offset' => $config->offset,
                'Visible' => $config->visible,
                'Override' => !$isDefault && $hasOverride,
                'IsDefault' => $isDefault,
                'FieldName' => $this->getName(),
                'WidthOptions' => $this->buildWidthOptions($columnCount, $config->width),
                'OffsetOptions' => $this->buildOptions(0, $columnCount - 1, $config->offset),
            ]));
        }

        return $list;
    }

    #[Override]
    public function performReadonlyTransformation(): ReadonlyField
    {
        $summary = $this->buildReadonlySummary();

        return ReadonlyField::create($this->name, $this->title, $summary);
    }

    /**
     * Normalize form submission data (string values) into a GridSettings VO.
     *
     * @param array<string, mixed> $formData
     */
    private function normalizeFormData(array $formData): GridSettings
    {
        $viewports = $this->adapter->getViewports();
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $columnCount = $this->adapter->getColumnCount();

        // Extract default viewport values
        $defaultEntry = $formData[$defaultKey] ?? null;
        $defaultWidth = is_array($defaultEntry) && is_numeric($defaultEntry['width'] ?? null)
            ? (int) $defaultEntry['width']
            : $columnCount;
        $defaultOffset = is_array($defaultEntry) && is_numeric($defaultEntry['offset'] ?? null)
            ? (int) $defaultEntry['offset']
            : 0;
        $defaultVisible = is_array($defaultEntry) && isset($defaultEntry['visible']);

        /** @var positive-int $defaultWidth */
        /** @var non-negative-int $defaultOffset */
        $default = new ViewportConfig($defaultWidth, $defaultOffset, $defaultVisible);
        $overrides = [];

        foreach ($viewports as $viewport) {
            $key = $viewport->key;

            if ($key === $defaultKey) {
                continue;
            }

            if (!isset($formData[$key]) || !is_array($formData[$key])) {
                continue;
            }

            $entry = $formData[$key];
            $hasOverride = isset($entry['override']);

            if (!$hasOverride) {
                continue;
            }

            $width = $entry['width'] ?? null;
            $offset = $entry['offset'] ?? null;

            /** @var positive-int $parsedWidth */
            $parsedWidth = is_numeric($width) ? (int) $width : $columnCount;
            /** @var non-negative-int $parsedOffset */
            $parsedOffset = is_numeric($offset) ? (int) $offset : 0;

            $overrides[$key] = new ViewportConfig(
                $parsedWidth,
                $parsedOffset,
                isset($entry['visible']),
            );
        }

        /** @var array<non-empty-string, ViewportConfig> $overrides */
        return new GridSettings($default, $overrides);
    }

    /**
     * Build width select options with "x/total" labels.
     *
     * @param positive-int $columnCount
     * @return ArrayList<ArrayData>
     */
    private function buildWidthOptions(int $columnCount, int $selected): ArrayList
    {
        $options = ArrayList::create();

        for ($i = 1; $i <= $columnCount; ++$i) {
            $options->push(ArrayData::create([
                'Value' => $i,
                'Label' => $i . '/' . $columnCount,
                'Selected' => $i === $selected,
            ]));
        }

        return $options;
    }

    /**
     * Build select options as an ArrayList of ArrayData.
     *
     * @return ArrayList<ArrayData>
     */
    private function buildOptions(int $min, int $max, int $selected): ArrayList
    {
        $options = ArrayList::create();

        for ($i = $min; $i <= $max; ++$i) {
            $options->push(ArrayData::create([
                'Value' => $i,
                'Label' => (string) $i,
                'Selected' => $i === $selected,
            ]));
        }

        return $options;
    }

    private function buildReadonlySummary(): string
    {
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $columnCount = $this->adapter->getColumnCount();
        $parts = [];

        // Default viewport
        $default = $this->gridSettings->default;
        $visibility = $default->visible ? '' : ' (hidden)';
        $parts[] = sprintf('%s: %d/%d+%d%s', $defaultKey, $default->width, $columnCount, $default->offset, $visibility);

        // Overrides
        foreach ($this->gridSettings->overrides as $key => $config) {
            $visibility = $config->visible ? '' : ' (hidden)';
            $parts[] = sprintf('%s: %d/%d+%d%s', $key, $config->width, $columnCount, $config->offset, $visibility);
        }

        return implode(', ', $parts);
    }
}
