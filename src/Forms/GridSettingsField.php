<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use SilverStripe\Forms\FormField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObjectInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;

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

    private GridAdapterInterface $adapter;

    public function __construct(string $name, GridAdapterInterface $adapter, ?string $title = null)
    {
        $this->adapter = $adapter;

        parent::__construct($name, $title ?? 'Grid Settings');
    }

    /**
     * Accept value from DB (JSON string) or form submission (nested array).
     *
     * @param mixed $value
     * @param array<string, mixed>|DataObjectInterface|null $data
     */
    #[\Override]
    public function setValue(mixed $value, mixed $data = null): static
    {
        if (is_string($value)) {
            $this->viewportData = $this->expandFromSparse($this->decodeSparse($value));
        } elseif (is_array($value)) {
            /** @var array<string, mixed> $value */
            $this->viewportData = $this->normalizeFormData($value);
        }

        return parent::setValue($value, $data); // @phpstan-ignore argument.type (DataObjectInterface is a ModelData in SS6)
    }

    /**
     * Compact viewport data back to sparse JSON and write into the record.
     */
    #[\Override]
    public function saveInto(DataObjectInterface $record): void
    {
        $sparse = $this->compactToSparse($this->viewportData);
        $record->{$this->name} = json_encode($sparse, JSON_FORCE_OBJECT & 0) ?: '{}';

        // json_encode on empty array produces '[]', but we want '{}'
        if ($sparse === []) {
            $record->{$this->name} = '{}';
        }
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

    #[\Override]
    public function performReadonlyTransformation(): ReadonlyField
    {
        $summary = $this->buildReadonlySummary();

        return ReadonlyField::create($this->name, $this->title, $summary);
    }

    /**
     * Expand sparse grid settings into a full viewport array with override flags.
     *
     * Walks viewports smallest→largest with mobile-first cascade to determine
     * each viewport's effective values from sparse data, then compares each
     * non-default viewport against the default viewport's effective values.
     *
     * @param array<string, array{width: int, offset: int, visible: bool}> $sparse
     * @return array<string, array{width: int, offset: int, visible: bool, override: bool}>
     */
    public function expandFromSparse(array $sparse): array
    {
        $viewports = $this->adapter->getViewports();
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $columnCount = $this->adapter->getColumnCount();

        // Pass 1: mobile-first cascade to determine effective values per viewport
        $effective = [];
        $prevWidth = $columnCount;
        $prevOffset = 0;
        $prevVisible = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $hasOverride = array_key_exists($key, $sparse);

            $effective[$key] = [
                'width' => $hasOverride ? $sparse[$key]['width'] : $prevWidth,
                'offset' => $hasOverride ? $sparse[$key]['offset'] : $prevOffset,
                'visible' => $hasOverride ? $sparse[$key]['visible'] : $prevVisible,
            ];

            $prevWidth = $effective[$key]['width'];
            $prevOffset = $effective[$key]['offset'];
            $prevVisible = $effective[$key]['visible'];
        }

        // Pass 2: compare each viewport against the default viewport's effective values
        $defaultValues = $effective[$defaultKey];
        $result = [];

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $isDefault = $key === $defaultKey;
            $vals = $effective[$key];

            $result[$key] = [
                'width' => $vals['width'],
                'offset' => $vals['offset'],
                'visible' => $vals['visible'],
                'override' => !$isDefault && (
                    $vals['width'] !== $defaultValues['width']
                    || $vals['offset'] !== $defaultValues['offset']
                    || $vals['visible'] !== $defaultValues['visible']
                ),
            ];
        }

        return $result;
    }

    /**
     * Compact full viewport data back to sparse storage.
     *
     * Walks smallest→largest. For each viewport, resolves the effective value
     * (own values if override=true or default viewport, else default viewport's
     * values). Stores only when effective differs from prev. This produces
     * sparse JSON that makes Column::getColumnClasses()'s mobile-first cascade
     * yield the correct results.
     *
     * @param array<string, array{width: int, offset: int, visible: bool, override: bool}> $full
     * @return array<string, array{width: int, offset: int, visible: bool}>
     */
    public function compactToSparse(array $full): array
    {
        $viewports = $this->adapter->getViewports();
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $columnCount = $this->adapter->getColumnCount();
        $sparse = [];

        // Read the default viewport's values (always present in full data)
        $defaultValues = isset($full[$defaultKey]) ? [
            'width' => $full[$defaultKey]['width'],
            'offset' => $full[$defaultKey]['offset'],
            'visible' => $full[$defaultKey]['visible'],
        ] : [
            'width' => $columnCount,
            'offset' => 0,
            'visible' => true,
        ];

        // Seed with implicit defaults for mobile-first cascade comparison
        $prevWidth = $columnCount;
        $prevOffset = 0;
        $prevVisible = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;

            if (!isset($full[$key])) {
                continue;
            }

            $entry = $full[$key];
            $isDefault = $key === $defaultKey;

            // Resolve effective value for this viewport
            if ($isDefault || $entry['override']) {
                $effWidth = $entry['width'];
                $effOffset = $entry['offset'];
                $effVisible = $entry['visible'];
            } else {
                // Non-overridden: uses default viewport values
                $effWidth = $defaultValues['width'];
                $effOffset = $defaultValues['offset'];
                $effVisible = $defaultValues['visible'];
            }

            // Store only if effective differs from previous in the cascade
            if ($effWidth !== $prevWidth || $effOffset !== $prevOffset || $effVisible !== $prevVisible) {
                $sparse[$key] = [
                    'width' => $effWidth,
                    'offset' => $effOffset,
                    'visible' => $effVisible,
                ];
            }

            $prevWidth = $effWidth;
            $prevOffset = $effOffset;
            $prevVisible = $effVisible;
        }

        return $sparse;
    }

    /**
     * Decode JSON string into sparse settings array.
     *
     * @return array<string, array{width: int, offset: int, visible: bool}>
     */
    private function decodeSparse(string $json): array
    {
        if ($json === '' || $json === '{}' || $json === '[]') {
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

        for ($i = 1; $i <= $columnCount; $i++) {
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

        for ($i = $min; $i <= $max; $i++) {
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
