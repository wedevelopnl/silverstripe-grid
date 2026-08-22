<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\Requirements;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Service\GridNodeMapper;
use WeDevelop\Grid\Service\SharedBlockService;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\ValidationError;

/**
 * The library's add control: a split button whose primary action creates a
 * section-rooted block and whose caret lists the other shapes a block may root
 * — row, column, and every element type a Column can hold.
 *
 * Replaces {@see \SilverStripe\Forms\GridField\GridFieldAddNewButton} in
 * {@see \WeDevelop\Grid\Admin\SharedBlockAdmin}. The stock button opens an
 * unsaved record, which cannot host the grid editor (it is keyed by the block's
 * id) and leaves the author to save before authoring anything; this one creates
 * block and root together and redirects into the editor.
 *
 * Self-contained by design. The block library is a ModelAdmin listing, not a
 * grid editor, so nothing here reaches into the editor's React bundle: the
 * choices are `GridField_FormAction`s, the create runs in {@see handleAction()},
 * and the caret menu's behaviour is a standalone script this component requires
 * itself. Admin chrome can then be repaired on its own — a shift in the CMS's
 * Bootstrap markup is a change to this class, its template and its script, with
 * no rebuild of the editor.
 *
 * The markup is the admin's own — `.btn-group` + `.dropdown-toggle-split` +
 * `.dropdown-menu` from its Bootstrap 5. Only the CSS ships with the CMS
 * though: Bootstrap's dropdown JavaScript is not in the admin bundle (its own
 * dropdowns are reactstrap), so {@see self::SCRIPT} supplies the toggle,
 * dismissal and roving keyboard navigation.
 */
class GridFieldAddSharedBlockButton extends AbstractGridFieldComponent implements
    GridField_HTMLProvider,
    GridField_ActionProvider
{
    /** Lowercase: {@see GridField::handleAlterAction()} matches case-insensitively. */
    private const string ACTION_NAME = 'addsharedblock';

    private const string SCRIPT = 'wedevelopnl/silverstripe-grid:client/js/shared-block-add.js';

    public function __construct(private readonly string $targetFragment = 'buttons-before-left')
    {
    }

    /**
     * @param GridField $gridField
     * @return array<string, DBHTMLText>
     */
    public function getHTMLFragments($gridField): array
    {
        if (!SharedBlock::singleton()->canCreate()) {
            return [];
        }

        Requirements::javascript(self::SCRIPT);

        $primary = $this->createAction(
            $gridField,
            'Section',
            ContainerType::Section->value,
            _t(self::class . '.ADD_BLOCK', 'Add new shared section'),
        );
        $primary->addExtraClass('btn btn-primary font-icon-plus-circled');
        $primary->setAttribute('data-testid', 'add-shared-block-add');

        $data = ArrayData::create([
            'PrimaryButton' => $primary->Field(),
            'MoreLabel' => _t(self::class . '.MORE_SHAPES', 'More block shapes'),
            'ElementsHeader' => _t(self::class . '.ELEMENTS_HEADER', 'Shared content element'),
            'ShapeItems' => $this->shapeItems($gridField),
            'ElementItems' => $this->elementItems($gridField),
        ]);

        return [$this->targetFragment => $data->renderWith(self::class)];
    }

    /**
     * @param GridField $gridField
     * @return list<string>
     */
    public function getActions($gridField): array
    {
        return [self::ACTION_NAME];
    }

    /**
     * Create the block and send the author into its editor.
     *
     * CSRF and the action dispatch are the GridField's own
     * ({@see GridField::gridFieldAlterAction()}); what is left here is the
     * switch from the chosen shape to a root class, the two permission gates,
     * and the redirect.
     *
     * $arguments travels through a {@see \SilverStripe\Forms\GridField\FormAction\StateStore},
     * which is session-backed by default but rebindable to `AttributeStore` —
     * where it becomes a request variable. So the shape is re-validated here
     * rather than trusted from the menu that offered it.
     *
     * @param string $actionName
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $data
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data): HTTPResponse
    {
        $rootClass = $this->resolveRootClass($arguments['root'] ?? null);

        if ($rootClass === null) {
            throw new HTTPResponse_Exception(
                _t(self::class . '.INVALID_SHAPE', 'That is not a shape a shared block can take.'),
                400,
            );
        }

        // Both gates: the library record is what the author is adding, and the
        // root is an element a project may forbid creating on its own.
        if (!SharedBlock::singleton()->canCreate() || !singleton($rootClass)->canCreate()) {
            throw new HTTPResponse_Exception(
                _t(self::class . '.CANNOT_CREATE', 'You do not have permission to add a shared block.'),
                403,
            );
        }

        $result = Injector::inst()->get(SharedBlockService::class)->create($rootClass);

        if ($result->isErr()) {
            $messages = array_map(
                static fn (ValidationError $error): string => $error->translate(),
                $result->errors(),
            );

            throw new HTTPResponse_Exception(implode(' ', $messages), 422);
        }

        $block = $result->unwrap();

        // AdminController::redirect() turns this into the header-based redirect
        // the CMS follows over ajax, so the author lands in the new block's
        // editor rather than back on the listing.
        return $gridField->getForm()->getController()->redirect((string) $block->getCMSEditLink());
    }

    /**
     * The two container shapes the caret offers beside the primary section.
     *
     * @return ArrayList<ArrayData>
     */
    private function shapeItems(GridField $gridField): ArrayList
    {
        $shapes = [
            'Row' => [ContainerType::Row, _t(self::class . '.ADD_ROW', 'Add new shared row')],
            'Column' => [ContainerType::Column, _t(self::class . '.ADD_COLUMN', 'Add new shared column')],
        ];

        $items = ArrayList::create();

        foreach ($shapes as $key => [$containerType, $label]) {
            $items->push(ArrayData::create([
                'Button' => $this->menuItem($gridField, $key, $containerType->value, $label),
            ]));
        }

        return $items;
    }

    /**
     * Every element type a Column accepts is exactly a shape a block may root,
     * and the same list {@see resolveRootClass()} re-validates against — one
     * source of truth, so the menu can never offer a type the write refuses.
     *
     * Listing them here is what removes the client-side type picker: the labels
     * are already translated on this side, so there is nothing to serialise.
     *
     * @return ArrayList<ArrayData>
     */
    private function elementItems(GridField $gridField): ArrayList
    {
        $mapper = Injector::inst()->get(GridNodeMapper::class);
        $leafTypes = $mapper->allowedTypesByContainerType()[ContainerType::Column->value];

        $items = ArrayList::create();

        foreach ($leafTypes as $className => $info) {
            $items->push(ArrayData::create([
                'Button' => $this->menuItem($gridField, $this->keyForClass($className), $className, $info['label']),
            ]));
        }

        return $items;
    }

    /**
     * The menu is a `role="menu"`, so its items are menuitems and none of them
     * is a tab stop — the trigger is, and the arrow keys move focus from there.
     *
     * @param non-empty-string $key
     * @param non-empty-string $shape
     */
    private function menuItem(GridField $gridField, string $key, string $shape, string $label): DBHTMLText
    {
        $action = $this->createAction($gridField, $key, $shape, $label);
        $action->addExtraClass('dropdown-item');
        $action->setAttribute('role', 'menuitem');
        $action->setAttribute('tabindex', '-1');

        return $action->Field();
    }

    /**
     * @param non-empty-string $key Distinguishes this action's field name and DOM id from its siblings.
     * @param non-empty-string $shape What {@see resolveRootClass()} receives back.
     */
    private function createAction(
        GridField $gridField,
        string $key,
        string $shape,
        string $label,
    ): GridField_FormAction {
        $action = GridField_FormAction::create(
            $gridField,
            'addSharedBlock' . $key,
            $label,
            self::ACTION_NAME,
            ['root' => $shape],
        );
        $action->setForm($gridField->getForm());

        return $action;
    }

    /**
     * @param class-string $className
     * @return non-empty-string
     */
    private function keyForClass(string $className): string
    {
        /** @var non-empty-string $key A class name always leaves at least one word character. */
        $key = str_replace('\\', '', $className);

        return $key;
    }

    /**
     * A shape name from the menu to the class its block is rooted with: one of
     * the three container types, or an element class a Column can hold.
     *
     * @return class-string<GridElement>|null
     */
    private function resolveRootClass(mixed $shape): ?string
    {
        if (!is_string($shape) || $shape === '') {
            return null;
        }

        $containerType = ContainerType::tryFrom($shape);

        if ($containerType !== null) {
            return $containerType->toElementClass();
        }

        if (!class_exists($shape) || !ContainerType::Column->isChildCreatable($shape)) {
            return null;
        }

        // A reference carries the block it stands for, which this action has no
        // slot for, and a reference may never root a block at all.
        if (is_a($shape, SharedBlockReference::class, true)) {
            return null;
        }

        return $shape;
    }
}
