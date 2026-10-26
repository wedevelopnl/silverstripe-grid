<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\MigrationIdMap;

/**
 * The first new element written for a legacy element.
 *
 * A legacy element becomes several new elements when its content area is
 * shared by several pages, or once per locale pass on a Fluent site. The first
 * copy takes the legacy element's owned items; every later copy is told which
 * element that was, so it can duplicate them.
 *
 * Stored rather than kept per run, so a copy written by a later run (a re-run
 * after a failure, a `--page-ids` run, a retried locale) still finds the first
 * copy an earlier run wrote. A page's rows are written inside its transaction:
 * a page that rolled back leaves none.
 *
 * @property int $LegacyElementID
 * @property int $ElementID
 * @property string|null $Locale the locale pass the copy was written in, null without Fluent
 */
final class MigratedFirstCopy extends DataObject
{
    private static string $table_name = 'WeDevelop_Grid_MigratedFirstCopy';

    /** @var array<string, string> */
    private static array $db = [
        'LegacyElementID' => 'Int',
        'ElementID' => 'Int',
        'Locale' => 'Varchar(20)',
    ];

    /** @var array<string, array{type: string, columns: list<string>}> */
    private static array $indexes = [
        'LegacyElementID' => ['type' => 'unique', 'columns' => ['LegacyElementID']],
    ];

    /**
     * Records the page's elements as first copies of the legacy elements no
     * earlier page or locale pass wrote.
     *
     * @param non-empty-string|null $locale the locale pass the page was written in, null without Fluent
     */
    public static function record(MigrationIdMap $page, ?string $locale): void
    {
        $elementIds = $page->elementIds();
        if ($elementIds === []) {
            return;
        }

        /** @var list<int> $recorded */
        $recorded = self::get()->filter(['LegacyElementID' => \array_keys($elementIds)])->column('LegacyElementID');
        foreach (\array_diff_key($elementIds, \array_flip($recorded)) as $legacyId => $elementId) {
            self::create(['LegacyElementID' => $legacyId, 'ElementID' => $elementId, 'Locale' => $locale])->write();
        }
    }

    public static function of(int $legacyId): ?GridElement
    {
        $firstCopy = self::get()->find('LegacyElementID', $legacyId);
        if (!$firstCopy instanceof self) {
            return null;
        }

        $locale = $firstCopy->Locale;

        return Versioned::withVersionedMode(static function () use ($firstCopy, $locale): ?GridElement {
            Versioned::set_stage(Versioned::DRAFT);
            $elements = GridElement::get();
            if ($locale !== null && $locale !== '') {
                // A copy from an earlier locale pass is hidden from this one by
                // Fluent's locale isolation unless the query names its locale.
                $elements = $elements->setDataQueryParam('Fluent.Locale', $locale);
            }

            return $elements->byID($firstCopy->ElementID);
        });
    }
}
