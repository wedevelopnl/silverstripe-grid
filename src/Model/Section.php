<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Top-level container in the Section > Row > Column hierarchy.
 * Lives under a page (via polymorphic Parent), never inside another container.
 * On draft-stage write, auto-scaffolds a child Row (which cascades
 * to create a Column) when no children exist.
 *
 * @property string $Zone
 * @method HasManyList<Row> Rows()
 * @implements ContainerInterface<Row>
 */
class Section extends GridElement implements ContainerInterface
{
    /** @use ContainerElementTrait<Row> */
    use ContainerElementTrait;

    private static string $table_name = 'WeDevelop_Grid_Section';

    private static string $singular_name = 'Section';

    private static string $plural_name = 'Sections';

    /** @var array<string, string> */
    private static array $db = [
        'Zone' => 'Varchar(50)',
    ];

    /** @var array<string, array<string, string|list<string>>> */
    private static array $indexes = [
        'Zone' => [
            'type' => 'index',
            'columns' => ['Zone'],
        ],
    ];

    private static string $icon = 'font-icon-block-layout';

    private static string $class_description = 'Top-level layout container that holds rows';

    private static bool $fluid_container = false;

    /** @var array<string, string> */
    private static array $summary_fields = [
        'Title' => 'Title',
        'getChildCountSummary' => 'Contents',
    ];

    /** @var array<string, string> */
    private static array $has_many = [
        'Rows' => Row::class . '.Parent',
    ];

    /** @var list<string> */
    private static array $owns = [
        'Rows',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'Rows',
    ];

    /** @var list<string> */
    private static array $cascade_duplicates = [
        'Rows',
    ];

    private static bool $auto_scaffold = true;

    #[Override]
    public function getChildren(): HasManyList
    {
        return $this->Rows();
    }

    /**
     * Zone is set by the editor from the grid field it was created in, never by
     * hand, so its scaffolded field is hidden. Declared here rather than in
     * GridElement::getCMSFields(): Zone is a Section column, and the base class
     * has no business knowing about a subclass's schema.
     */
    #[Override]
    public function getCMSFields(): FieldList
    {
        $this->beforeUpdateCMSFields(static function (FieldList $fields): void {
            $fields->removeByName('Zone');
        });

        return parent::getCMSFields();
    }

    #[Override]
    public function getContainerType(): ContainerType
    {
        return ContainerType::Section;
    }

    /** CSS classes for the grid container wrapper. */
    public function getContainerClasses(): string
    {
        /** @var bool $fluid */
        $fluid = static::config()->get('fluid_container');
        $classes = $this->gridAdapter->getContainerClass($fluid);

        $this->extend('updateContainerClasses', $classes);

        return $classes;
    }

    #[Override]
    protected function ensureSortSet(): void
    {
        if ($this->Sort > 0) {
            return;
        }

        // Section::get(), not static::get(): sections in a zone form one Sort
        // sequence across all Section subclasses, so a project subclass must not
        // scope the max to its own class and collide with base-Section siblings.
        $max = Section::get()
            ->filter([
                'ParentID' => $this->ParentID,
                'ParentClass' => $this->ParentClass,
                'Zone' => $this->Zone ?: '',
            ])
            ->max('Sort');

        $this->Sort = (is_numeric($max) ? (int) $max : 0) + 1;
    }

}
