<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use SilverStripe\Core\Validation\FieldValidation\FieldValidator;
use SilverStripe\Core\Validation\ValidationResult;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Validates GridSettings business rules at the storage layer.
 *
 * Enforces that width, offset, and their combination are within
 * the grid adapter's column count — for both the default config
 * and all viewport overrides.
 *
 * Registered on DBGridSettings via $field_validators. Runs during
 * DataObject::write() and blocks the write on failure.
 */
final class GridSettingsFieldValidator extends FieldValidator
{
    /**
     * @param positive-int $columnCount
     */
    public function __construct(
        string $name,
        mixed $value,
        private readonly int $columnCount,
    ) {
        parent::__construct($name, $value);
    }

    protected function validateValue(): ValidationResult
    {
        $result = ValidationResult::create();

        if (!$this->value instanceof GridSettings) {
            return $result;
        }

        $settings = $this->value;

        $this->validateViewportConfig($result, $settings->default, 'default');

        foreach ($settings->overrides as $viewport => $config) {
            /** @var non-empty-string $viewport */
            $this->validateViewportConfig($result, $config, $viewport);
        }

        return $result;
    }

    /**
     * Lower-bound enforcement (width >= 1, offset >= 0) is intentionally absent here.
     * Those constraints are guaranteed at the input boundaries: `RequestBodyParser` (API path),
     * `GridSettingsField::normalizeFormData` (CMS form clamp), and the `ViewportConfig`
     * `positive-int`/`int<0,max>` type contract. This validator checks upper bounds only.
     */
    private function validateViewportConfig(
        ValidationResult $result,
        ViewportConfig $config,
        string $viewport,
    ): void {
        if ($config->width > $this->columnCount) {
            $result->addFieldError(
                $this->name,
                sprintf(
                    'Width %d for viewport "%s" exceeds %d columns.',
                    $config->width,
                    $viewport,
                    $this->columnCount,
                ),
            );
        }

        if ($config->offset >= $this->columnCount) {
            $result->addFieldError(
                $this->name,
                sprintf(
                    'Offset %d for viewport "%s" exceeds maximum of %d.',
                    $config->offset,
                    $viewport,
                    $this->columnCount - 1,
                ),
            );
        }

        if ($config->width + $config->offset > $this->columnCount) {
            $result->addFieldError(
                $this->name,
                sprintf(
                    'Width %d plus offset %d for viewport "%s" exceeds %d columns.',
                    $config->width,
                    $config->offset,
                    $viewport,
                    $this->columnCount,
                ),
            );
        }
    }
}
