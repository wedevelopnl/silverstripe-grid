<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBHTMLText;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Service\GridNodeMapper;
use WeDevelop\Grid\Value\ContainerType;

/**
 * The library's "Add new shared section" control: a split button whose primary
 * action creates a section-rooted block and whose caret offers the other three
 * shapes a block may root — row, column, or a single content element.
 *
 * Replaces {@see \SilverStripe\Forms\GridField\GridFieldAddNewButton} in
 * {@see \WeDevelop\Grid\Admin\SharedBlockAdmin}. The stock button opens an
 * unsaved record, which cannot host the grid editor (it is keyed by the block's
 * id) and leaves the author to save before authoring anything; this one creates
 * block and root together and redirects into the editor.
 *
 * The markup here is only the mount point plus a fallback: the caret menu and
 * the element-type picker are React components the CMS bundle mounts onto the
 * outer element. That fallback is the stock link — with no bundle the author
 * still reaches the save-first form, which {@see SharedBlock::getCMSFields()}
 * still serves. It carries the same label as the mounted button so the control
 * does not rename itself when the bundle fails, but it is the degraded path:
 * that form saves a rootless block, leaving the author to add the section in
 * the editor rather than getting one scaffolded.
 */
class GridFieldAddSharedBlockButton extends AbstractGridFieldComponent implements GridField_HTMLProvider
{
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

        /** @var GridNodeMapper $mapper */
        $mapper = Injector::inst()->get(GridNodeMapper::class);

        // The leaf types a Column accepts are exactly the leaf types that may
        // root a block, and they are what the create endpoint validates against
        // — one source of truth, so the picker can never offer a type the write
        // would refuse.
        $leafTypes = $mapper->allowedTypesByContainerType()[ContainerType::Column->value];

        $data = ArrayData::create([
            'NewLink' => Controller::join_links($gridField->Link('item'), 'new'),
            'ButtonName' => _t(
                self::class . '.ADD_BLOCK',
                'Add new shared section',
            ),
            // Base64, not raw JSON: the template layer resolves the `\\` escape
            // sequences JSON uses for namespace separators, so
            // `WeDevelop\\Grid\\Model\\X` reaches the browser as
            // `WeDevelop\Grid\Model\X` — invalid JSON that parses to nothing and
            // leaves the element picker empty. Base64 carries no character the
            // escaping can touch.
            'LeafTypes' => base64_encode(json_encode($leafTypes, JSON_THROW_ON_ERROR)),
        ]);

        return [$this->targetFragment => $data->renderWith(self::class)];
    }
}
