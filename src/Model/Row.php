<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Mid-level container in the Section > Row > Column hierarchy.
 * Lives inside a Section only. On draft-stage write, auto-scaffolds
 * a child Column when no children exist.
 *
 * @method HasManyList<Column> Columns()
 * @implements ContainerInterface<Column>
 */
class Row extends GridElement implements ContainerInterface
{
    /** @use ContainerElementTrait<Column> */
    use ContainerElementTrait;

    private static string $table_name = 'WeDevelop_Grid_Row';

    private static string $singular_name = 'Row';

    private static string $plural_name = 'Rows';

    private static string $icon = 'font-icon-columns';

    private static string $class_description = 'Horizontal container that holds columns within a section';

    /** @var array<string, string> */
    private static array $summary_fields = [
        'Title' => 'Title',
        'getChildCountSummary' => 'Contents',
    ];

    /** @var array<string, string> */
    private static array $has_many = [
        'Columns' => Column::class . '.Parent',
    ];

    /** @var list<string> */
    private static array $owns = [
        'Columns',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'Columns',
    ];

    /** @var list<string> */
    private static array $cascade_duplicates = [
        'Columns',
    ];

    private static bool $auto_scaffold = true;

    #[Override]
    public function getChildren(): HasManyList
    {
        return $this->Columns();
    }

    #[Override]
    public function getContainerType(): ContainerType
    {
        return ContainerType::Row;
    }

    /** CSS classes for the grid row wrapper. */
    public function getRowClasses(): string
    {
        $classes = $this->gridAdapter->getRowClasses();

        $this->extend('updateRowClasses', $classes);

        return $classes;
    }

    /** @return list<string> */
    #[Override]
    protected function provideHolderClasses(): array
    {
        $rowClasses = $this->getRowClasses();

        return $rowClasses !== '' ? [$rowClasses] : [];
    }

}
