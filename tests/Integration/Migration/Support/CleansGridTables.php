<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestCustomElement;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestPage;

/**
 * Deletes all grid element rows between tests for the migration suites, which
 * run without SapphireTest's per-test transaction rollback (the legacy-table
 * seeder's DDL auto-commits in MySQL, breaking savepoint nesting).
 *
 * The table list is derived from the class manifest — every GridElement
 * subclass plus the host class's extra_dataobjects, each with its base and
 * _Live table — so adding a new element table or test DataObject can never
 * silently leak rows across tests. The shared migration test-support classes
 * are always included: usesTransactions=false means rows persist across test
 * CLASSES in the same process, so a suite that never writes TestPage itself
 * still needs to clear rows another suite left behind.
 */
trait CleansGridTables
{
    private function cleanGridTables(): void
    {
        $schema = DataObject::getSchema();

        /** @var list<class-string<DataObject>> $classes */
        $classes = \array_values(\array_unique([
            ...\array_values(ClassInfo::subclassesFor(GridElement::class)),
            ...static::$extra_dataobjects,
            TestCustomElement::class,
            TestPage::class,
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
