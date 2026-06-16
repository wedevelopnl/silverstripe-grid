<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Minimal DataObject without Versioned extension for testing
 * code paths that require non-versioned records.
 */
class UnversionedDataObject extends DataObject implements TestOnly
{
    private static string $table_name = 'WeDevelop_Grid_Test_Unversioned';

    /** @var array<string, string> */
    private static array $db = [
        'Title' => 'Varchar(255)',
    ];
}
