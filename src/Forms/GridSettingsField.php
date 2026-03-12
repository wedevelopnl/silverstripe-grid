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
use WeDevelop\Grid\Service\GridSettingsCompactor;

/**
 * Per-viewport grid settings field for Column elements.
 *
 * Uses a default-viewport-anchored model: the adapter's default viewport is
 * the primary configuration; other viewports only store overrides that differ.
 * Handles the full lifecycle: JSON decode on load (expand sparse → full),
 * per-viewport controls in the template, sparse JSON encode on save (compact).
 */
class GridSettingsField extends FormField
{
    /** @var array<string, array{width: int, offset: int, visible: bool, override: bool}> */
    private array $viewportData = [];

    private readonly GridSettingsCompactor $compactor;

    public function __construct(string $name, private readonly GridAdapterInterface $adapter, ?string $title = null)
    {
        $this->compactor = new GridSettingsCompactor($this->adapter);

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
        if (is_string($value)) {
            $this->viewportData = $this->expandFromSparse($this->decodeSparse($value));
        } elseif (is_array($value)) {
            /** @var array<string, mixed> $value */
            $this->viewportData = $this->normalizeFormData($value);
        }

        return parent::setValue($value, $data);
    }

    /**
     * Compact viewport data back to sparse JSON and write into the record.
     */
    #[Override]
    public function saveInto(DataObjectInterface $record): void
    {
        $sparse = $this->compactToSparse($this->viewportData);
        $record->{$this->name} = $sparse === [] ? '{}' : json_encode($sparse, JSON_FORCE_OBJECT);
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
            $data = $this->viewportData[$key] ?? $this->getDefaults();

            $list->push(ArrayData::create([
                'Key' => $key,
                'Label' => $viewport->label,
                'Width' => $data['width'],
                'Offset' => $data['offset'],
                'Visible' => $data['visible'],
                'Override' => $data['override'],
                'IsDefault' => $key === $defaultKey,
                'FieldName' => $this->getName(),
                'WidthOptions' => $this->buildWidthOptions($columnCount, $data['width']),
                'OffsetOptions' => $this->buildOptions(0, $columnCount - 1, $data['offset']),
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
     * Expand sparse grid settings into a full viewport array with override flags.
     *
     * @param array<string, array{width: int, offset: int, visible: bool}> $sparse
     * @return array<string, array{width: int, offset: int, visible: bool, override: bool}>
     */
    public function expandFromSparse(array $sparse): array
    {
        return $this->compactor->expandFromSparse($sparse);
    }

    /**
     * Compact full viewport data back to sparse storage.
     *
     * @param array<string, array{width: int, offset: int, visible: bool, override: bool}> $full
     * @return array<string, array{width: int, offset: int, visible: bool}>
     */
    public function compactToSparse(array $full): array
    {
        return $this->compactor->compactToSparse($full);
    }

    /**
     * Decode JSON string into sparse settings array.
     *
     * @return array<string, array{width: int, offset: int, visible: bool}>
     */
    private function decodeSparse(string $json): array
    {
        if (in_array($json, ['', '{}', '[]'], true)) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, array{width: int, offset: int, visible: bool}> $decoded */
        return $decoded;
    }

    /**
     * Normalize form submission data (string values) into typed array.
     *
     * Two-pass: first extract default viewport values, then for each non-default
     * viewport use submitted values if override is checked, else copy default values.
     *
     * @param array<string, mixed> $formData
     * @return array<string, array{width: int, offset: int, visible: bool, override: bool}>
     */
    private function normalizeFormData(array $formData): array
    {
        $viewports = $this->adapter->getViewports();
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $columnCount = $this->adapter->getColumnCount();

        // Pass 1: extract default viewport values
        $defaultEntry = $formData[$defaultKey] ?? null;
        $defaultWidth = is_array($defaultEntry) && is_numeric($defaultEntry['width'] ?? null)
            ? (int) $defaultEntry['width']
            : $columnCount;
        $defaultOffset = is_array($defaultEntry) && is_numeric($defaultEntry['offset'] ?? null)
            ? (int) $defaultEntry['offset']
            : 0;
        $defaultVisible = is_array($defaultEntry) && isset($defaultEntry['visible']);

        // Pass 2: build full data
        $result = [];

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $isDefault = $key === $defaultKey;

            if ($isDefault) {
                $result[$key] = [
                    'width' => $defaultWidth,
                    'offset' => $defaultOffset,
                    'visible' => $defaultVisible,
                    'override' => false,
                ];
                continue;
            }

            if (!isset($formData[$key]) || !is_array($formData[$key])) {
                // Non-default viewport with no form data: use default values, no override
                $result[$key] = [
                    'width' => $defaultWidth,
                    'offset' => $defaultOffset,
                    'visible' => $defaultVisible,
                    'override' => false,
                ];
                continue;
            }

            $entry = $formData[$key];
            $hasOverride = isset($entry['override']);

            if ($hasOverride) {
                $width = $entry['width'] ?? null;
                $offset = $entry['offset'] ?? null;

                $result[$key] = [
                    'width' => is_numeric($width) ? (int) $width : $columnCount,
                    'offset' => is_numeric($offset) ? (int) $offset : 0,
                    'visible' => isset($entry['visible']),
                    'override' => true,
                ];
            } else {
                // Disabled controls don't submit — use default viewport values
                $result[$key] = [
                    'width' => $defaultWidth,
                    'offset' => $defaultOffset,
                    'visible' => $defaultVisible,
                    'override' => false,
                ];
            }
        }

        return $result;
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

    /** @return array{width: int, offset: int, visible: bool, override: bool} */
    private function getDefaults(): array
    {
        return [
            'width' => $this->adapter->getColumnCount(),
            'offset' => 0,
            'visible' => true,
            'override' => false,
        ];
    }

    private function buildReadonlySummary(): string
    {
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $parts = [];

        foreach ($this->viewportData as $key => $data) {
            if ($key !== $defaultKey && !$data['override']) {
                continue;
            }

            $visibility = $data['visible'] ? '' : ' (hidden)';
            $parts[] = sprintf('%s: %d/%d+%d%s', $key, $data['width'], $this->adapter->getColumnCount(), $data['offset'], $visibility);
        }

        return $parts !== [] ? implode(', ', $parts) : 'Default (full width)';
    }
}
