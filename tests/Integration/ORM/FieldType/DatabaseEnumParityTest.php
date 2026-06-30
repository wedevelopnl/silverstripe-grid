<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\ORM\FieldType;

use BackedEnum;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBEnum;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Sanity invariant, not a unit test of any one class: every DB Enum column
 * whose value set is owned by a PHP value enum must list exactly that enum's
 * cases.
 *
 * A column's Enum member list is a hardcoded string in `$db` — PHP cannot
 * compute it in a static property — so without this guard, adding a case to the
 * backing enum would silently make the new value unstorable (MySQL coerces an
 * out-of-range Enum write to ''). Register each (model, field, enum) triple in
 * {@see enumBackedColumns}; the assertion itself is generic, so a future enum
 * column is covered by adding one row.
 *
 * Config-only: it reads field definitions, no database round-trip.
 */
#[CoversNothing]
final class DatabaseEnumParityTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * Every DB Enum column backed by a PHP value enum. Add a row when a new
     * enum-backed column is introduced.
     *
     * @return iterable<string, array{class-string<DataObject>, string, class-string<BackedEnum>}>
     */
    public static function enumBackedColumns(): iterable
    {
        yield 'ContentElement.VerticalAlignment' => [ContentElement::class, 'VerticalAlignment', VerticalAlignment::class];
        yield 'ContentElement.MediaRatio' => [ContentElement::class, 'MediaRatio', AspectRatio::class];
        yield 'ContentElement.MediaPosition' => [ContentElement::class, 'MediaPosition', MediaPosition::class];
    }

    /**
     * @param class-string<DataObject> $modelClass
     * @param class-string<BackedEnum> $enumClass
     */
    #[DataProvider('enumBackedColumns')]
    public function testDbEnumMembersMatchBackingEnum(string $modelClass, string $field, string $enumClass): void
    {
        $dbField = DataObject::singleton($modelClass)->dbObject($field);

        self::assertInstanceOf(
            DBEnum::class,
            $dbField,
            sprintf('%s.%s must be a native DB Enum so its domain is constrained at the schema level', $modelClass, $field),
        );

        $expected = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enumClass::cases());
        sort($expected);

        $actual = array_values($dbField->getEnum());
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            sprintf('%s.%s Enum members have drifted from %s; update the $db Enum string', $modelClass, $field, $enumClass),
        );
    }
}
