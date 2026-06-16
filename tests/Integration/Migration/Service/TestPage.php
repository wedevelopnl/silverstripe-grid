<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Dev\TestOnly;

/**
 * Test-only Page subclass for verifying that migration uses
 * the concrete page class name in polymorphic ParentClass fields.
 */
class TestPage extends \Page implements TestOnly
{
    private static string $table_name = 'WeDevelop_Grid_Test_Page';

    /** @var array<string, string> */
    private static array $db = [
        'Subtitle' => 'Varchar',
    ];
}
