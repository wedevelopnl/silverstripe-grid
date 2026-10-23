<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use SilverStripe\ORM\DataObject;

/**
 * Resolves which table in a class's ancestry stores each of a set of fields.
 *
 * The media fields the migration writes come from an optional extension that a
 * site may apply to `ContentElement`, to its own subclass, or not at all — so
 * neither the table holding them nor their existence is known at compile time.
 */
final class FieldTableResolver
{
    /**
     * @param class-string<DataObject> $class
     * @param list<non-empty-string> $fields
     * @return array{
     *     byTable: array<non-empty-string, list<non-empty-string>>,
     *     missing: list<non-empty-string>,
     * } `byTable` groups the held fields under the table that stores them;
     *   `missing` lists the fields no table in the ancestry holds
     */
    public static function resolve(string $class, array $fields): array
    {
        $schema = DataObject::getSchema();
        $byTable = [];
        $missing = [];

        foreach ($fields as $field) {
            $fieldClass = $schema->classForField($class, $field);
            if ($fieldClass === null) {
                $missing[] = $field;
                continue;
            }

            /** @var non-empty-string $table */
            $table = $schema->tableName($fieldClass);
            $byTable[$table][] = $field;
        }

        return ['byTable' => $byTable, 'missing' => $missing];
    }
}
