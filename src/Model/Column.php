<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Service\ColumnClassResolver;
use WeDevelop\Grid\Service\GridSettingsResolver;
use WeDevelop\Grid\ORM\FieldType\DBGridSettings;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridSettings;

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

    private static string $table_name = 'WeDevelop_Grid_Column';

    private static string $singular_name = 'Column';

    private static string $plural_name = 'Columns';

    private static string $icon = 'font-icon-block-content';

    private static string $class_description = 'Responsive grid column that holds content blocks';

    // gridAdapter is declared on GridElement; SilverStripe merges $dependencies
    // down the hierarchy, so only the addition is listed here.
    /** @var array<string, string> */
    private static array $dependencies = [
        'gridSettingsResolver' => '%$' . GridSettingsResolver::class,
    ];

    public GridSettingsResolver $gridSettingsResolver;

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

    /** @var array<string, class-string> */
    private static array $db = [
        'GridSettings' => DBGridSettings::class,
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
        $this->beforeUpdateCMSFields(function (FieldList $fields): void {
            $fields->removeByName('GridSettings');

            $fields->addFieldToTab(
                'Root.Grid',
                GridSettingsField::create('GridSettings', $this->gridAdapter),
            );
        });

        return parent::getCMSFields();
    }

    /** Returns the default viewport's width as a fraction, e.g. '6/12'. */
    public function getGridWidthSummary(): string
    {
        $settings = $this->getGridSettings();
        $columnCount = $this->gridAdapter->getColumnCount();

        return sprintf('%d/%d', $settings->default->width, $columnCount);
    }

    /** Retrieve grid settings from the composite DB field. */
    public function getGridSettings(): GridSettings
    {
        $field = $this->dbObject('GridSettings');

        return $field->getValue() ?? GridSettings::initial($this->gridAdapter->getColumnCount());
    }

    /**
     * Write grid settings into the composite DB field.
     *
     * Accepts a GridSettings VO (primary API) or a JSON string for backward
     * compatibility with YAML fixture loading, where the framework passes
     * raw field values through the setter.
     */
    public function setGridSettings(GridSettings|string $settings): static
    {
        $field = $this->dbObject('GridSettings');
        $field->setValue($settings);

        return $this;
    }

    /** CSS classes for the grid column wrapper. */
    public function getColumnClasses(): string
    {
        $effective = $this->gridSettingsResolver->resolveEffective($this->getGridSettings());
        $classes = ColumnClassResolver::resolve($effective, $this->gridAdapter);

        $this->extend('updateColumnClasses', $classes);

        return $classes;
    }

    /** @return list<string> */
    #[Override]
    protected function provideHolderClasses(): array
    {
        $columnClasses = $this->getColumnClasses();

        return $columnClasses !== '' ? [$columnClasses] : [];
    }

    #[Override]
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $gridSettings = $this->dbObject('GridSettings');
        if (!$this->isInDB() && !$gridSettings->exists()) {
            $this->setGridSettings(GridSettings::initial($this->gridAdapter->getColumnCount()));
        }
    }
}
