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

    /**
     * No test reads Subtitle — it exists so the subclass gets a table at all.
     * A DataObject subclass that adds no fields of its own is not given one,
     * and the subclass-table tests ALTER this table to add the legacy columns.
     *
     * @var array<string, string>
     */
    private static array $db = [
        'Subtitle' => 'Varchar',
    ];
}
