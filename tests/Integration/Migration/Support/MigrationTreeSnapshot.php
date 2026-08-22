<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use PHPUnit\Framework\Assert;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Read-only golden-master snapshot of a migrated grid hierarchy.
 *
 * Captures the observed Section > Row > Column > element tree for one page on a
 * given stage as a plain nested array, so a characterization test can pin the
 * CURRENT output of the SS5→SS6 migration pipeline. This helper never writes;
 * it only reads.
 *
 * Two read details are load-bearing for the golden master:
 *
 * 1. Every level is sorted by `Sort ASC, ID ASC` so the captured order is
 *    deterministic and reproduces the persisted tree order.
 * 2. Each Column's `GridSettingsOverrides` column is read RAW via
 *    {@see DB::prepared_query} against the stage-appropriate Column table, NOT
 *    through the {@see \WeDevelop\Grid\Value\GridSettings} value object. The VO
 *    normalises `NULL` and `[]` to the same empty-overrides state and erases
 *    the storage tri-state (`NULL` = no overrides, JSON string = at least one
 *    viewport override). The golden master must distinguish those, so it reads
 *    the column bytes directly.
 *
 * @phpstan-type SnapshotElement array{
 *     title: string,
 *     showTitle: bool,
 *     titleTag: string,
 *     titleClass: string,
 *     extraClass: string,
 *     sort: int,
 *     className: string,
 *     html: string|null,
 * }
 * @phpstan-type SnapshotColumn array{
 *     sort: int,
 *     gridDefault: array{width: positive-int, offset: int<0, max>, visible: bool},
 *     overridesColumnRaw: string|null,
 *     elements: list<SnapshotElement>,
 * }
 * @phpstan-type SnapshotRow array{
 *     title: string,
 *     extraClass: string,
 *     sort: int,
 *     columns: list<SnapshotColumn>,
 * }
 * @phpstan-type SnapshotSection array{
 *     extraClass: string,
 *     sort: int,
 *     rows: list<SnapshotRow>,
 * }
 */
final class MigrationTreeSnapshot
{
    private const string COLUMN_TABLE = 'WeDevelop_Grid_Column';

    private const string GRID_ELEMENT_TABLE = 'WeDevelop_Grid_GridElement';

    /**
     * Snapshot the full migrated tree for a page on a stage.
     *
     * @param positive-int     $pageId
     * @param class-string     $pageClass
     * @param non-empty-string $zone
     * @param non-empty-string $stage  one of {@see Versioned::DRAFT} / {@see Versioned::LIVE}
     *
     * @return list<SnapshotSection>
     */
    public static function snapshotTree(int $pageId, string $pageClass, string $zone, string $stage): array
    {
        return Versioned::withVersionedMode(static function () use ($pageId, $pageClass, $zone, $stage): array {
            Versioned::set_stage($stage);

            $sections = Section::get()->filter([
                'ParentID' => $pageId,
                'ParentClass' => $pageClass,
                'Zone' => $zone,
            ])->sort('Sort ASC, ID ASC');

            $result = [];
            foreach ($sections as $section) {
                $result[] = [
                    'extraClass' => (string) $section->ExtraClass,
                    'sort' => (int) $section->Sort,
                    'rows' => self::snapshotRows((int) $section->ID, $stage),
                ];
            }

            return $result;
        });
    }

    /**
     * Raw `UseGrid` flag for the page on a stage, read from the `Page[_Live]` table.
     *
     * @param positive-int     $pageId
     * @param non-empty-string $stage
     */
    public static function useGridFlag(int $pageId, string $stage): int
    {
        $table = self::stageTable('Page', $stage);

        $value = DB::prepared_query(
            \sprintf('SELECT "UseGrid" FROM "%s" WHERE "ID" = ?', $table),
            [$pageId],
        )->value();

        return (int) $value;
    }

    /**
     * Whether the page has any row of $elementClass on a stage, in a zone.
     *
     * Convenience for "did this page get a grid tree on this stage". ParentID,
     * Zone and ClassName all live on the base GridElement table, so this is a
     * single-table read — the element class is matched by ClassName rather than
     * by which subclass table the row appears in, which is what a class with no
     * own $db fields (Section) no longer has.
     *
     * @param class-string<GridElement> $elementClass matched inclusive of subclasses
     * @param positive-int     $pageId
     * @param class-string     $pageClass polymorphic parent class (page IDs and element IDs share a numeric namespace)
     * @param non-empty-string $zone
     * @param non-empty-string $stage
     */
    public static function recordExistsOnStage(string $elementClass, int $pageId, string $pageClass, string $zone, string $stage): bool
    {
        $baseTable = self::stageTable(self::GRID_ELEMENT_TABLE, $stage);

        // Inclusive of subclasses, matching the old subclass-table join: a row of
        // any Section subclass lived in the Section table and counted here.
        $classNames = array_values(ClassInfo::subclassesFor($elementClass, true));
        $placeholders = implode(', ', array_fill(0, count($classNames), '?'));

        $count = DB::prepared_query(
            \sprintf(
                'SELECT COUNT(*) FROM "%s" AS "base" '
                . 'WHERE "base"."ParentID" = ? AND "base"."ParentClass" = ? AND "base"."Zone" = ? '
                . 'AND "base"."ClassName" IN (%s)',
                $baseTable,
                $placeholders,
            ),
            [$pageId, $pageClass, $zone, ...$classNames],
        )->value();

        return (int) $count > 0;
    }

    /**
     * Every element Title in the snapshot tree, in tree order.
     *
     * @param list<SnapshotSection> $tree
     *
     * @return list<string>
     */
    public static function allElementTitles(array $tree): array
    {
        $titles = [];
        foreach ($tree as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['columns'] as $column) {
                    foreach (self::columnElementTitles($column) as $title) {
                        $titles[] = $title;
                    }
                }
            }
        }

        return $titles;
    }

    /**
     * Titles of the elements in one snapshot column, in element order.
     *
     * @param SnapshotColumn $column
     *
     * @return list<string>
     */
    public static function columnElementTitles(array $column): array
    {
        $titles = [];
        foreach ($column['elements'] as $element) {
            $titles[] = $element['title'];
        }

        return $titles;
    }

    /**
     * The first column array whose elements include $title, or null.
     *
     * @param list<SnapshotSection> $tree
     * @param non-empty-string      $title
     *
     * @return SnapshotColumn|null
     */
    public static function findColumnContaining(array $tree, string $title): ?array
    {
        foreach ($tree as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['columns'] as $column) {
                    if (\in_array($title, self::columnElementTitles($column), true)) {
                        return $column;
                    }
                }
            }
        }

        return null;
    }

    /**
     * HTML of the element with $title inside one snapshot column, or null.
     *
     * @param SnapshotColumn   $column
     * @param non-empty-string $title
     */
    public static function elementHtml(array $column, string $title): ?string
    {
        foreach ($column['elements'] as $element) {
            if ($element['title'] === $title) {
                return $element['html'];
            }
        }

        return null;
    }

    /**
     * Assert a Column's raw GridSettingsOverrides JSON carries the expected
     * lg width override (the WS4 tri-state content check).
     *
     * @param positive-int $expectedWidth
     */
    public static function assertLgOverrideWidth(?string $raw, int $expectedWidth): void
    {
        Assert::assertIsString($raw, 'Column must persist a non-null JSON overrides column');
        Assert::assertStringContainsString('"lg"', $raw, 'Column carries the lg override');
        $decoded = json_decode($raw, true);
        Assert::assertIsArray($decoded);
        Assert::assertArrayHasKey('lg', $decoded, 'Overrides JSON contains the lg viewport key');
        Assert::assertSame(
            $expectedWidth,
            $decoded['lg']['width'] ?? null,
            \sprintf('lg override width is %d', $expectedWidth),
        );
    }

    /**
     * @param non-empty-string $stage
     *
     * @return list<SnapshotRow>
     */
    private static function snapshotRows(int $sectionId, string $stage): array
    {
        $rows = Row::get()->filter([
            'ParentID' => $sectionId,
            'ParentClass' => Section::class,
        ])->sort('Sort ASC, ID ASC');

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'title' => (string) $row->Title,
                'extraClass' => (string) $row->ExtraClass,
                'sort' => (int) $row->Sort,
                'columns' => self::snapshotColumns((int) $row->ID, $stage),
            ];
        }

        return $result;
    }

    /**
     * @param non-empty-string $stage
     *
     * @return list<SnapshotColumn>
     */
    private static function snapshotColumns(int $rowId, string $stage): array
    {
        $columns = Column::get()->filter([
            'ParentID' => $rowId,
            'ParentClass' => Row::class,
        ])->sort('Sort ASC, ID ASC');

        $result = [];
        foreach ($columns as $column) {
            $columnId = (int) $column->ID;
            $result[] = [
                'sort' => (int) $column->Sort,
                'gridDefault' => $column->getGridSettings()->default->toArray(),
                'overridesColumnRaw' => self::readOverridesRaw($columnId, $stage),
                'elements' => self::snapshotElements($columnId, $stage),
            ];
        }

        return $result;
    }

    /**
     * @param non-empty-string $stage
     *
     * @return list<SnapshotElement>
     */
    private static function snapshotElements(int $columnId, string $stage): array
    {
        $elements = GridElement::get()->filter([
            'ParentID' => $columnId,
            'ParentClass' => Column::class,
        ])->sort('Sort ASC, ID ASC');

        $result = [];
        foreach ($elements as $element) {
            $result[] = [
                'title' => (string) $element->Title,
                'showTitle' => (bool) $element->ShowTitle,
                'titleTag' => (string) $element->TitleTag,
                'titleClass' => (string) $element->TitleClass,
                'extraClass' => (string) $element->ExtraClass,
                'sort' => (int) $element->Sort,
                'className' => (string) $element->ClassName,
                'html' => $element instanceof ContentElement ? (string) $element->HTML : null,
            ];
        }

        return $result;
    }

    /**
     * Read a Column's `GridSettingsOverrides` column raw, preserving the
     * NULL-vs-JSON tri-state (see the class docblock).
     *
     * @param non-empty-string $stage
     */
    private static function readOverridesRaw(int $columnId, string $stage): ?string
    {
        $table = self::stageTable(self::COLUMN_TABLE, $stage);

        $value = DB::prepared_query(
            \sprintf('SELECT "GridSettingsOverrides" FROM "%s" WHERE "ID" = ?', $table),
            [$columnId],
        )->value();

        return \is_string($value) ? $value : null;
    }

    /**
     * @param non-empty-string $baseTable
     * @param non-empty-string $stage
     *
     * @return non-empty-string
     */
    private static function stageTable(string $baseTable, string $stage): string
    {
        return $stage === Versioned::LIVE ? $baseTable . '_Live' : $baseTable;
    }
}
