<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * A project's own row settings, as a converting site adds them to the grid's Row.
 *
 * @extends Extension<\WeDevelop\Grid\Model\Row>
 */
final class TestRowSettingsExtension extends Extension implements TestOnly
{
    private static array $db = [
        'BackgroundColour' => 'Varchar(50)',
    ];

    private static array $has_one = [
        'RowImage' => Image::class,
    ];
}
