<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use SilverStripe\ORM\DB;

/**
 * Existence check for the legacy tables the migration reads by raw SQL.
 */
final class LegacyTables
{
    /**
     * Whether a base table exists. Reads DB::table_list(), which leaves out
     * views, rather than DB::get_schema()->hasTable(), whose SHOW TABLES LIKE
     * matches them: a hand-made view must not pass for a legacy table.
     */
    public static function exists(string $table): bool
    {
        // DB::table_list() keys are lowercase.
        return \array_key_exists(\strtolower($table), DB::table_list());
    }
}
