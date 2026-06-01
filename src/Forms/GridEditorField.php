<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use Override;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\FieldType\DBHTMLText;
use WeDevelop\Grid\Model\GridElement;

/**
 * Grid editor field that renders a bare `<div>` with data attributes
 * that the entwine bridge reads to mount the React application. All
 * grid mutations are handled by the GridController API endpoints,
 * not by the CMS form, so this field needs no data binding.
 *
 * Extends `GridField` to inherit URL routing via `GridFieldDetailForm`:
 * element edit forms are reached via `field/GridEditor/item/{id}/edit`
 * URLs, which the parent's request handler resolves. The CMS History
 * viewer's `DataObjectVersionFormFactory` would normally strip every
 * `GridField` subclass from the form — we avoid that by aliasing the
 * factory to `WeDevelop\Grid\Forms\GridAwareVersionFormFactory` which
 * skips `GridEditorField` in its override of the strip step.
 */
class GridEditorField extends GridField
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
        parent::__construct(
            $name,
            '',
            GridElement::get(),
            GridFieldConfig::create()->addComponent(GridFieldDetailForm::create()),
        );

        $this->schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;
        $this->setSchemaComponent('GridEditorField');

        $this->addExtraClass('no-change-track');
        $this->setAttribute('data-react-mount', 'grid-editor');
    }

    /**
     * Skip GridField's expensive table rendering (iterates the full list
     * and calls canView() on every record). Delegates to template rendering
     * which outputs the React mount `<div>`.
     *
     * @param array<string, mixed> $properties
     * @return DBHTMLText
     */
    #[Override] // @phpstan-ignore method.childReturnType, typeCoverage.returnTypeCoverage (matching untyped parent signature; renderWith returns DBHTMLText, not string)
    public function FieldHolder($properties = [])
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
