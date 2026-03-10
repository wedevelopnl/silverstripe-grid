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
 * Handles the full lifecycle: JSON decode on load (expand sparse → full),
 * per-viewport controls in the template, sparse JSON encode on save (compact).
 */
class GridSettingsField extends FormField
{
    /** @var array<string, array{width: int, offset: int, visible: bool, inherit: bool}> */
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
        $firstKey = $viewports[0]->key;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $data = $this->viewportData[$key] ?? $this->getDefaults();

            $list->push(ArrayData::create([
                'Key' => $key,
                'Label' => $viewport->label,
                'Width' => $data['width'],
                'Offset' => $data['offset'],
                'Visible' => $data['visible'],
                'Inherit' => $data['inherit'],
                'IsFirst' => $key === $firstKey,
                'FieldName' => $this->getName(),
                'WidthOptions' => $this->buildOptions(1, $columnCount, $data['width']),
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
     * Expand sparse grid settings into a full viewport array with inherit flags.
     *
     * Previous effective values are seeded with implicit defaults, so the first
     * viewport follows the same code path as all others — override or inherit.
     * The only special case: the first viewport's inherit flag is forced to false
     * (inheriting from "nothing" is meaningless in the UI).
     *
     * @param array<string, array{width: int, offset: int, visible: bool}> $sparse
     * @return array<string, array{width: int, offset: int, visible: bool, inherit: bool}>
     */
    public function expandFromSparse(array $sparse): array
    {
        $viewports = $this->adapter->getViewports();
        $firstKey = $viewports[0]->key;
        $result = [];

        // Seed with implicit defaults so the first viewport needs no special case
        $prevWidth = $this->adapter->getColumnCount();
        $prevOffset = 0;
        $prevVisible = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $hasOverride = array_key_exists($key, $sparse);

            $result[$key] = [
                'width' => $hasOverride ? $sparse[$key]['width'] : $prevWidth,
                'offset' => $hasOverride ? $sparse[$key]['offset'] : $prevOffset,
                'visible' => $hasOverride ? $sparse[$key]['visible'] : $prevVisible,
                'inherit' => !$hasOverride && $key !== $firstKey,
            ];

            $prevWidth = $result[$key]['width'];
            $prevOffset = $result[$key]['offset'];
            $prevVisible = $result[$key]['visible'];
        }

        return $result;
    }

    /**
     * Compact full viewport data back to sparse storage.
     *
     * Inheriting viewports are skipped. Non-inheriting viewports are stored
     * only when their values differ from the previous effective values.
     * Previous values are seeded with implicit defaults, so the first viewport
     * (which always has inherit=false) compares against defaults naturally.
     *
     * @param array<string, array{width: int, offset: int, visible: bool, inherit: bool}> $full
     * @return array<string, array{width: int, offset: int, visible: bool}>
     */
    public function compactToSparse(array $full): array
    {
        $viewports = $this->adapter->getViewports();
        $sparse = [];

        // Seed with implicit defaults so the first viewport needs no special case
        $prevWidth = $this->adapter->getColumnCount();
        $prevOffset = 0;
        $prevVisible = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;

            if (!isset($full[$key]) || $full[$key]['inherit']) {
                continue;
            }

            $entry = $full[$key];

            if ($entry['width'] !== $prevWidth || $entry['offset'] !== $prevOffset || $entry['visible'] !== $prevVisible) {
                $sparse[$key] = [
                    'width' => $entry['width'],
                    'offset' => $entry['offset'],
                    'visible' => $entry['visible'],
                ];
            }

            $prevWidth = $entry['width'];
            $prevOffset = $entry['offset'];
            $prevVisible = $entry['visible'];
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
     * HTML checkboxes don't submit when unchecked — absence means false.
     *
     * @param array<string, mixed> $formData
     * @return array<string, array{width: int, offset: int, visible: bool, inherit: bool}>
     */
    private function normalizeFormData(array $formData): array
    {
        $result = [];
        $viewports = $this->adapter->getViewports();

        foreach ($viewports as $viewport) {
            $key = $viewport->key;

            if (!isset($formData[$key]) || !is_array($formData[$key])) {
                continue;
            }

            $entry = $formData[$key];
            $width = $entry['width'] ?? null;
            $offset = $entry['offset'] ?? null;

            $result[$key] = [
                'width' => is_numeric($width) ? (int) $width : $this->adapter->getColumnCount(),
                'offset' => is_numeric($offset) ? (int) $offset : 0,
                'visible' => isset($entry['visible']),
                'inherit' => isset($entry['inherit']),
            ];
        }

        return $result;
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

    /** @return array{width: int, offset: int, visible: bool, inherit: bool} */
    private function getDefaults(): array
    {
        return [
            'width' => $this->adapter->getColumnCount(),
            'offset' => 0,
            'visible' => true,
            'inherit' => false,
        ];
    }

    private function buildReadonlySummary(): string
    {
        $parts = [];

        foreach ($this->viewportData as $key => $data) {
            if ($data['inherit']) {
                continue;
            }

            $visibility = $data['visible'] ? '' : ' (hidden)';
            $parts[] = sprintf('%s: %d/%d+%d%s', $key, $data['width'], $this->adapter->getColumnCount(), $data['offset'], $visibility);
        }

        return $parts !== [] ? implode(', ', $parts) : 'Default (full width)';
    }
}
