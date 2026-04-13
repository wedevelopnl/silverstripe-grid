<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use Override;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Grid editor field that renders a bare `<div>` with data attributes
 * that the entwine bridge reads to mount the React application. All
 * grid mutations are handled by the GridController API endpoints,
 * not by the CMS form, so this field needs no data binding.
 *
 * Note: intentionally extends FormField rather than GridField, because
 * DataObjectVersionFormFactory strips all GridField instances from the
 * form before rendering history views — extending GridField would cause
 * the readonly history viewer to disappear.
 */
class GridEditorField extends FormField
{
    private bool $isReadonlyField = false;

    /** @var positive-int|null */
    private ?int $version = null;

    /**
     * @param positive-int $pageId
     * @param non-empty-string $zone
     */
    public function __construct(string $name, private readonly int $pageId, private readonly string $zone = 'main')
    {
        parent::__construct($name, '');

        $this->schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;
        $this->setSchemaComponent('GridEditorField');

        $this->addExtraClass('grid-editor__container no-change-track');
    }

    /**
     * Delegates to template rendering which outputs the React mount `<div>`.
     *
     * @param array<string, mixed> $properties
     * @return DBHTMLText
     */
    #[Override] // @phpstan-ignore typeCoverage.returnTypeCoverage (matching untyped parent signature; renderWith returns DBHTMLText, not string)
    public function FieldHolder($properties = []) // @phpstan-ignore typeCoverage.paramTypeCoverage (matching untyped parent signature)
    {
        $context = $this;

        if (count($properties)) {
            $context = $this->customise($properties);
        }

        return $context->renderWith($this->getFieldHolderTemplates());
    }

    /** @return positive-int */
    public function getPageId(): int
    {
        return $this->pageId;
    }

    /** @return non-empty-string */
    public function getZone(): string
    {
        return $this->zone;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function getSchemaDataDefaults(): array
    {
        /** @var array<string, mixed> $schemaData */
        $schemaData = parent::getSchemaDataDefaults();

        // Legacy HTML path (main edit view): entwine bridge reads these
        // top-level keys from the rendered <div>'s data-schema attribute.
        $schemaData['grid-page-id'] = $this->pageId;
        $schemaData['grid-zone'] = $this->zone;

        // React FormBuilder path (history viewer): the wrapper component
        // reads from the nested data sub-array, which FormBuilder passes
        // as component props.
        /** @var array<string, mixed> $data */
        $data = $schemaData['data'] ?? [];
        $data['pageId'] = $this->pageId;
        $data['zone'] = $this->zone;

        if ($this->isReadonlyField) {
            $schemaData['grid-readonly'] = true;
            $data['readonly'] = true;

            if ($this->version !== null) {
                $schemaData['grid-version'] = $this->version;
                $data['version'] = $this->version;
            }
        }

        $schemaData['data'] = $data;

        return $schemaData;
    }

    /**
     * No-op: element mutations are handled by the API controller, not
     * the CMS form. The base FormField::saveInto() would call
     * setCastedField on the record — which we don't want because the
     * grid editor submits no POST data for this field.
     */
    #[Override]
    public function saveInto(DataObjectInterface $record): void
    {
        // Intentionally empty
    }

    #[Override]
    public function performReadonlyTransformation(): self
    {
        $clone = clone $this;
        $clone->isReadonlyField = true;
        $clone->setReadonly(true);

        // getForm() and getRecord() PHPDocs declare non-nullable return
        // types, but both return null at runtime when unset.
        /** @var mixed $rawVersion */
        $rawVersion = $this->getForm()?->getRecord()?->Version; // @phpstan-ignore-line nullsafe.neverNull (getForm() returns null at runtime when field has no form)

        $version = filter_var($rawVersion, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version !== false) {
            $clone->version = $version;
        }

        return $clone;
    }
}
