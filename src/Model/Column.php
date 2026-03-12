<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Service\ColumnClassResolver;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Leaf container in the Section > Row > Column hierarchy.
 * Holds responsive grid settings and non-container content elements.
 *
 * @method HasManyList<GridElement> Elements()
 * @implements ContainerInterface<GridElement>
 */
class Column extends GridElement implements ContainerInterface
{
    /** @use ContainerElementTrait<GridElement> */
    use ContainerElementTrait;

    private static string $table_name = 'Column';

    private static string $singular_name = 'Column';

    private static string $plural_name = 'Columns';

    private static string $icon = 'font-icon-block-content';

    private static string $class_description = 'Responsive grid column that holds content blocks';

    /** @var array<string, string> */
    private static array $dependencies = [
        'gridAdapter' => '%$' . GridAdapterInterface::class,
    ];

    public GridAdapterInterface $gridAdapter;

    /** @var array<string, string> */
    private static array $summary_fields = [
        'Title' => 'Title',
        'getChildCountSummary' => 'Contents',
        'getGridWidthSummary' => 'Width',
    ];

    /** @var array<string, string> */
    private static array $has_many = [
        'Elements' => GridElement::class . '.Parent',
    ];

    /** @var list<string> */
    private static array $owns = [
        'Elements',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'Elements',
    ];

    /** @var list<string> */
    private static array $cascade_duplicates = [
        'Elements',
    ];

    /** @var array<string, string> */
    private static array $db = [
        'GridSettings' => 'Text',
    ];

    #[Override]
    public function getChildren(): HasManyList
    {
        return $this->Elements();
    }

    #[Override]
    public function getContainerType(): ContainerType
    {
        return ContainerType::Column;
    }

    #[Override]
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('GridSettings');

        $fields->addFieldToTab(
            'Root.Grid',
            GridSettingsField::create('GridSettings', $this->gridAdapter),
        );

        return $fields;
    }

    /** Render through the holder template. */
    #[Override]
    public function forTemplate(): string
    {
        /** @var DBHTMLText $result */
        $result = $this->renderWith('WeDevelop/Grid/Layout/ColumnHolder');

        return (string) $result;
    }

    /** Inner content rendered by `$Element` in the holder template. */
    public function Element(): DBHTMLText
    {
        return $this->renderWith('WeDevelop/Grid/Model/Column');
    }

    /** Returns the smallest viewport's width as a fraction, e.g. '6/12'. */
    public function getGridWidthSummary(): string
    {
        $settings = $this->getGridSettingsData();
        $columnCount = $this->gridAdapter->getColumnCount();

        $firstKey = array_key_first($settings);

        if ($firstKey === null) {
            return sprintf('%d/%d', $columnCount, $columnCount);
        }

        return sprintf('%d/%d', $settings[$firstKey]['width'], $columnCount);
    }

    /**
     * Decode the JSON grid settings into an associative array.
     * Returns empty array when no stored value exists (sparse storage).
     *
     * @return array<non-empty-string, array{width: positive-int, offset: int<0, max>, visible: bool}>
     */
    public function getGridSettingsData(): array
    {
        $raw = $this->getField('GridSettings');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<non-empty-string, array{width: positive-int, offset: int<0, max>, visible: bool}> $decoded */
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Encode an associative array of grid settings into JSON for storage.
     *
     * @param array<non-empty-string, array{width: positive-int, offset: int<0, max>, visible: bool}> $settings
     */
    public function setGridSettingsData(array $settings): static
    {
        $this->setField('GridSettings', json_encode($settings));

        return $this;
    }

    /**
     * CSS classes for the grid column wrapper using mobile-first cascade.
     *
     * @see ColumnClassResolver::resolve() for the cascade algorithm.
     */
    public function getColumnClasses(): string
    {
        $classes = ColumnClassResolver::resolve(
            $this->getGridSettingsData(),
            $this->gridAdapter,
        );

        $this->extend('updateColumnClasses', $classes);

        return $classes;
    }

    #[Override]
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        // Initialize empty sparse grid settings for new records
        if (!$this->isInDB() && !$this->getField('GridSettings')) {
            $this->setGridSettingsData([]);
        }
    }
}
