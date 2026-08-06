<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Model\GridElement;

/**
 * Purges every grid ORM table between tests, for test cases that run with
 * `$usesTransactions = false` — MySQL DDL auto-commits, so SapphireTest's
 * per-test rollback is disabled there and leftover rows would otherwise leak
 * into the next test (intermittently, since `executionOrder=random`).
 *
 * The table list is derived from the class manifest — every `GridElement`
 * subclass plus the test class's own `$extra_dataobjects`, each with its base
 * and `_Live` table. A new element table or test DataObject is therefore
 * cleaned automatically; the hardcoded lists this replaces had already drifted
 * apart from one another.
 */
trait CleansGridTables
{
    protected function cleanGridTables(): void
    {
        $schema = DataObject::getSchema();

        /** @var list<class-string<DataObject>> $classes */
        $classes = \array_values(\array_unique([
            ...\array_values(ClassInfo::subclassesFor(GridElement::class)),
            ...static::$extra_dataobjects,
        ]));

        $allTables = DB::table_list();

        foreach ($classes as $class) {
            $table = $schema->tableName($class);
            if ($table === '') {
                continue;
            }

            foreach ([$table, $table . '_Live'] as $candidate) {
                if (\array_key_exists(\strtolower($candidate), $allTables)) {
                    DB::query("DELETE FROM \"{$candidate}\"");
                }
            }
        }
    }
}
