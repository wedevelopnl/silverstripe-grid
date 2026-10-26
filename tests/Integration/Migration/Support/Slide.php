<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;

/**
 * An item a block owns, like a slider's slide or a gallery's image: on a
 * legacy site its `SliderID` holds the legacy block's ID.
 *
 * Named after the item of the `onAfterMigrate` recipe in docs/migration.md, so
 * {@see TestSlideRecipe} is that recipe word for word.
 *
 * @property string $Title
 * @property int $Sort
 * @property int $SliderID
 * @mixin Versioned
 */
final class Slide extends DataObject implements TestOnly
{
    private static string $table_name = 'WeDevelop_Grid_Test_Slide';

    /** @var array<string, string> */
    private static array $db = [
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    /** @var array<string, class-string> */
    private static array $has_one = [
        'Slider' => GridElement::class,
    ];

    /** @var list<class-string> */
    private static array $extensions = [
        Versioned::class,
    ];
}
