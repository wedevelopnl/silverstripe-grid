<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Admin;

use SilverStripe\Admin\ModelAdmin;
use WeDevelop\Grid\Model\GridElement;

class GridElementAdmin extends ModelAdmin
{
    private static string $url_segment = 'grid-elements';

    private static string $menu_title = 'Grid Elements';

    /** @var list<class-string> */
    private static array $managed_models = [GridElement::class];

    private static string $menu_icon_class = 'font-icon-block-layout';
}
