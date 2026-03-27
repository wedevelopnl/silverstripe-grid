<?php

declare(strict_types=1);

namespace WeDevelop\Grid\ORM\FieldType;

use Override;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\FieldValidation\CompositeFieldValidator;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\FieldType\DBComposite;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Service\GridSettingsSerializer;
use WeDevelop\Grid\Validation\GridSettingsFieldValidator;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Composite DB field that stores GridSettings as typed sub-columns.
 *
 * Default viewport config (width, offset, visible) is stored as 3 typed
 * columns. Per-viewport overrides are stored as JSON in a Text column,
 * only parsed when non-empty.
 *
 * Physical columns (prefixed with the field name):
 * - {Name}DefaultWidth    INT         — default viewport width
 * - {Name}DefaultOffset   INT         — default viewport offset
 * - {Name}DefaultVisible  BOOLEAN     — default viewport visibility
 * - {Name}Overrides       MEDIUMTEXT  — JSON overrides (null when none)
 */
final class DBGridSettings extends DBComposite
{
    /** @var array<string, array<string, string|null>|null> */
    private static array $field_validators = [
        CompositeFieldValidator::class => null,
        GridSettingsFieldValidator::class => ['columnCount' => 'getColumnCount'],
    ];

    /** @var array<string, string> */
    private static array $composite_db = [
        'DefaultWidth' => 'Int',
        'DefaultOffset' => 'Int',
        'DefaultVisible' => 'Boolean',
        'Overrides' => 'Text',
    ];

    /**
     * Reconstruct the GridSettings VO from sub-fields.
     *
     * Returns null when no data has been stored (e.g., unsaved record).
     */
    public function getValue(): ?GridSettings
    {
        /** @var positive-int|null $width */
        $width = $this->getField('DefaultWidth');

        if ($width === null) {
            return null;
        }

        /** @var int $offset */
        $offset = $this->getField('DefaultOffset') ?? 0;
        $visible = (bool) ($this->getField('DefaultVisible') ?? true);

        $default = new ViewportConfig($width, $offset, $visible);

        $overrides = GridSettingsSerializer::deserializeOverrides($this->getField('Overrides'));

        return new GridSettings($default, $overrides);
    }

    /**
     * Accept a GridSettings VO, JSON string, array, or another DBComposite and decompose into sub-fields.
     *
     * JSON string input is supported for backward compatibility with YAML fixture loading,
     * where SilverStripe's fixture factory passes field values as raw strings.
     *
     * @param GridSettings|string|array<string, mixed>|DBComposite|null $value
     * @param array<string, mixed>|ModelData|null $record
     */
    #[Override]
    public function setValue(mixed $value, null|array|ModelData $record = null, bool $markChanged = true): static
    {
        if ($value instanceof GridSettings) {
            return $this->applyGridSettings($value, $record, $markChanged);
        }

        if (is_string($value)) {
            $parsed = GridSettingsSerializer::fromJson($value);
            if ($parsed instanceof GridSettings) {
                return $this->applyGridSettings($parsed, $record, $markChanged);
            }

            // Non-parseable strings (empty, invalid JSON) fall through to parent
            return parent::setValue(null, $record, $markChanged);
        }

        return parent::setValue($value, $record, $markChanged);
    }

    /**
     * Decompose a GridSettings VO into composite sub-fields.
     *
     * @param array<string, mixed>|ModelData|null $record
     */
    private function applyGridSettings(GridSettings $value, null|array|ModelData $record, bool $markChanged): static
    {
        // Bind first if we have a parent record
        if ($record instanceof ModelData) {
            parent::setValue(null, $record, $markChanged);
        }

        $this->setField('DefaultWidth', $value->default->width);
        $this->setField('DefaultOffset', $value->default->offset);
        $this->setField('DefaultVisible', $value->default->visible);
        $this->setField('Overrides', GridSettingsSerializer::serializeOverrides($value->overrides));

        return $this;
    }

    /**
     * GridSettings exists when at least the default width is stored.
     *
     * Overrides the parent which requires ALL sub-fields to exist —
     * Overrides is legitimately empty for most records.
     */
    #[Override]
    public function exists(): bool
    {
        $width = $this->getField('DefaultWidth');

        return $width !== null;
    }

    /**
     * Provide the GridSettings VO for validation instead of sub-field array.
     */
    #[Override]
    public function getValueForValidation(): ?GridSettings
    {
        return $this->getValue();
    }

    /**
     * Column count from the active grid adapter, used by GridSettingsFieldValidator.
     *
     * @return positive-int
     */
    public function getColumnCount(): int
    {
        return Injector::inst()->get(GridAdapterInterface::class)->getColumnCount();
    }

    /**
     * Suppress form field scaffolding — GridSettingsField is added explicitly in Column::getCMSFields().
     *
     * @param array<string, mixed> $params
     */
    #[Override]
    public function scaffoldFormField(?string $title = null, array $params = []): ?FormField
    {
        return null;
    }

}
