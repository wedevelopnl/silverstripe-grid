<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Task\OneTime;

use SilverStripe\ORM\Connect\DBSchemaManager;
use SilverStripe\ORM\DB;

/**
 * Schema access for the one-time repair tasks, which read and write raw rows
 * on every stage table and must skip a table a site does not have.
 */
trait ReadsDatabaseSchema
{
    /** The connection's schema manager, which is only ever null before boot. */
    private function dbSchema(): DBSchemaManager
    {
        $dbSchema = DB::get_schema();
        assert($dbSchema instanceof DBSchemaManager);

        return $dbSchema;
    }
}
