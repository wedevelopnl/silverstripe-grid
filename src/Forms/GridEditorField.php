<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use Override;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObjectInterface;
use WeDevelop\Grid\Model\GridElement;

/**
 * Grid editor field that extends GridField to inherit URL routing for
 * element edit forms via GridFieldDetailForm. Renders a bare `<div>`
 * with data attributes that the entwine bridge reads to mount the
 * React application — GridField's table rendering is bypassed via
 * the FieldHolder() override.
 */
class GridEditorField extends GridField
{
    public function __construct(string $name, private readonly int $pageId, private readonly string $zone = 'main')
    {
        parent::__construct(
            $name,
            '',
            GridElement::get(),
            GridFieldConfig::create()->addComponent(GridFieldDetailForm::create()),
        );

        $this->addExtraClass('grid-editor__container no-change-track');
    }

    /**
     * Skip GridField's expensive table rendering (iterates the full list
     * and calls canView() on every record). Delegates to template rendering
     * which outputs the React mount <div>.
     *
     * @param array<string, mixed> $properties
     * @return DBHTMLText
     */
    #[Override] // @phpstan-ignore method.childReturnType, typeCoverage.returnTypeCoverage (matching untyped parent signature; renderWith returns DBHTMLText, not string)
    public function FieldHolder($properties = []) // @phpstan-ignore typeCoverage.paramTypeCoverage (matching untyped parent signature)
    {
        $context = $this;

        if (count($properties)) {
            $context = $this->customise($properties);
        }

        return $context->renderWith($this->getFieldHolderTemplates());
    }

    public function getPageId(): int
    {
        return $this->pageId;
    }

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

        $schemaData['grid-page-id'] = $this->pageId;
        $schemaData['grid-zone'] = $this->zone;

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

    #[Override] // @phpstan-ignore method.childReturnType (GridField returns self but we intentionally return a LiteralField to strip all grid functionality in read-only mode)
    public function performReadonlyTransformation(): LiteralField
    {
        return LiteralField::create($this->name, '');
    }
}
