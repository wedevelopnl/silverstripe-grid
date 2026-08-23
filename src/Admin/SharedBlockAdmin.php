<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Admin;

use Override;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Versioned\GridFieldArchiveAction;
use WeDevelop\Grid\Forms\GridFieldAddSharedBlockButton;
use WeDevelop\Grid\Forms\SharedBlockItemRequest;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * The shared block library. Each block's edit form embeds the same grid editor
 * the page edit form uses, rooted at the block instead of a page zone — one
 * editor, two hosts.
 */
class SharedBlockAdmin extends ModelAdmin
{
    /**
     * The library is gated on PAGE access, not on its own section code.
     * Managing a shared block is maintaining page content in one place
     * ({@see SharedBlock::ADMIN_PERMISSION}, which the model checks per record);
     * leaving this at the default would have made the screen separately
     * grantable, so a page editor could edit blocks through the API but not
     * open the library.
     *
     * The literal matches the code CMSMain registers; see
     * {@see SharedBlock} for why the FQCN-suffixed form would deny everyone.
     */
    private static string|array $required_permission_codes = 'CMS_ACCESS_CMSMain';

    private static string $url_segment = 'shared-blocks';

    private static string $menu_title = 'Shared blocks';

    private static string $menu_icon_class = 'font-icon-block-layout';

    private static int $menu_priority = -1;

    /** @var array<class-string> */
    private static array $managed_models = [
        SharedBlock::class,
    ];

    /**
     * Swaps the stock add button for one that creates the block and its root in
     * one step. The stock button opens an unsaved record, which cannot host the
     * grid editor and offers no choice of root shape.
     *
     * It is inserted before the button it replaces, which is only then removed,
     * so it inherits that slot: fragments concatenate in component order, and
     * `GridFieldConfig_RecordEditor` registers the stock button ahead of
     * ModelAdmin's export, print and import buttons. Appending instead would
     * land the primary action last in the row, behind Import CSV.
     *
     * The detail form gets its own item request for the delete action, which
     * has to name what happens to the pages placing the block
     * ({@see SharedBlockItemRequest}).
     *
     * The row's own removal actions go with it. Deleting a block reaches every
     * page that places it and the outcome cannot be inferred — the pages either
     * lose the content or keep it as their own copy — so the listing, which has
     * no room to ask, must not offer a one-click answer. Both components are
     * removed, not just the archive: {@see GridFieldArchiveAction::augmentColumns()}
     * is what suppresses the stock delete for a versioned model, so dropping the
     * archive alone would promote the row action from an archive to a permanent
     * delete. Removal lives in the edit form, behind the two named actions.
     */
    #[Override]
    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();

        $config->addComponent(GridFieldAddSharedBlockButton::create(), GridFieldAddNewButton::class);
        $config->removeComponentsByType(GridFieldAddNewButton::class);

        $config->removeComponentsByType([GridFieldArchiveAction::class, GridFieldDeleteAction::class]);

        $config->getComponentByType(GridFieldDetailForm::class)
            ?->setItemRequestClass(SharedBlockItemRequest::class);

        return $config;
    }
}
