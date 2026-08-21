<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Admin;

use SilverStripe\Admin\ModelAdmin;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * The shared block library. Each block's edit form embeds the same grid editor
 * the page edit form uses, rooted at the block instead of a page zone — one
 * editor, two hosts.
 */
class SharedBlockAdmin extends ModelAdmin
{
    private static string $url_segment = 'shared-blocks';

    private static string $menu_title = 'Shared blocks';

    private static string $menu_icon_class = 'font-icon-block-layout';

    private static int $menu_priority = -1;

    /** @var array<class-string> */
    private static array $managed_models = [
        SharedBlock::class,
    ];
}
