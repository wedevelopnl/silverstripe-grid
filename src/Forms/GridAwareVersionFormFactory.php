<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use LogicException;
use Override;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use SilverStripe\VersionedAdmin\Forms\DataObjectVersionFormFactory;

/**
 * History viewer form factory that preserves `GridEditorField` instances.
 *
 * The stock `DataObjectVersionFormFactory::getFormFields()` calls a
 * private `removeGridFields()` that strips every `GridField` subclass
 * from the form before serialization. That would erase our grid editor
 * (which extends `GridField` for URL routing) from the history viewer.
 *
 * This subclass re-implements the same pipeline but replaces the strip
 * step with one that skips `GridEditorField`. It is wired into the DI
 * container via `_config/history-viewer.yml` as an alias for the base
 * factory, so all CMS code that injects `DataObjectVersionFormFactory`
 * resolves to this class instead.
 */
class GridAwareVersionFormFactory extends DataObjectVersionFormFactory
{
    /**
     * Rebuilds the parent's private `getFormFields()` pipeline with one
     * difference: `GridEditorField` survives the `GridField` strip step.
     *
     * @param array{Record?: DataObject, ...} $context
     */
    #[Override] // @phpstan-ignore missingType.return, typeCoverage.returnTypeCoverage, missingType.parameter (matching the untyped parent signature)
    protected function getFormFields(?RequestHandler $controller, $name, $context = [])
    {
        /** @var DataObject $record */
        $record = $context['Record'] ?? throw new LogicException('Missing required context Record');
        /** @var FieldList $fields */
        $fields = $record->getCMSFields();

        $this->removeHistoryViewerFields($fields);
        $this->removeSelectedRightTitles($fields);
        $this->removeGridFieldsExceptGridEditor($fields);

        // Parent extension hook — matches the stock factory's flow.
        $this->invokeWithExtensions('updateFormFields', $fields, $controller, $name, $context);

        return $fields;
    }

    /**
     * Strip every GridField subclass except our GridEditorField, which
     * has its own React schema component and renders correctly inside
     * the history viewer.
     */
    private function removeGridFieldsExceptGridEditor(FieldList $fields): void
    {
        $fields->recursiveWalk(static function (FormField $field): void {
            if (!$field instanceof GridField) {
                return;
            }

            if ($field instanceof GridEditorField) {
                return;
            }

            $container = $field->getContainerFieldList();
            if ($container !== null) {
                $container->remove($field);
            }
        });
    }
}
