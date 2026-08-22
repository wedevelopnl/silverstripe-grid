<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Admin;

use Override;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig;
use WeDevelop\Grid\Forms\GridFieldAddSharedBlockButton;
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
     */
    #[Override]
    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();

        $config->addComponent(GridFieldAddSharedBlockButton::create(), GridFieldAddNewButton::class);
        $config->removeComponentsByType(GridFieldAddNewButton::class);

        return $config;
    }
}
