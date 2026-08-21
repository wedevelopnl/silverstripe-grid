<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;

/**
 * Adds grid editing capability to SiteTree pages.
 *
 * Provides the has_many relationship for top-level Sections and
 * injects the GridEditorField into the CMS editing form. A per-page
 * toggle allows switching between the grid editor and the default
 * Content HTMLEditorField.
 *
 * @extends Extension<SiteTree>
 * @method HasManyList<GridElement> GridRoots()
 * @method HasManyList<Section> Sections()
 */
class GridPageExtension extends Extension
{
    private static bool $use_grid_by_default = true;

    private static bool $enable_editor_toggle = false;

    /** @var array<string, string> */
    private static array $db = [
        'UseGrid' => 'Boolean(1)',
    ];

    /**
     * `GridRoots` is the OWNED relation and covers every root element class a
     * zone holds — ordinary Sections and the shared-block placements sitting
     * beside them. Ownership goes through this one base-class relation on
     * purpose: two has_many relations to the same polymorphic `.Parent` make
     * the reverse owner lookup ambiguous and silently break the page's publish
     * cascade, and listing both in `$cascade_duplicates` would copy every
     * Section twice.
     *
     * `Sections` stays declared for `$Sections` in templates and existing
     * project code, but is deliberately NOT owned — `GridRoots` covers it. A
     * placement declares no `$owns` of its own to `Block`, so page publish,
     * archive and duplicate reach the placement and stop there.
     *
     * @var array<string, string>
     */
    private static array $has_many = [
        'GridRoots' => GridElement::class . '.Parent',
        'Sections' => Section::class . '.Parent',
    ];

    /** @var list<string> */
    private static array $owns = [
        'GridRoots',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'GridRoots',
    ];

    /** @var list<string> */
    private static array $cascade_duplicates = [
        'GridRoots',
    ];

    /**
     * Every root element of a zone, in Sort order — Sections and shared-block
     * placements merged into the single list a template should loop over.
     *
     * `$Sections` still works and is still supported, but it is a Section-only
     * relation and so never includes shared blocks. Templates that want them
     * use `<% loop $GridZone('main') %>$Me<% end_loop %>`.
     *
     * @return ArrayList<GridElement>
     */
    public function GridZone(string $zone): ArrayList
    {
        $owner = $this->getOwner();

        /** @var positive-int $ownerId */
        $ownerId = $owner->ID;

        // Delegated rather than re-queried here: which classes root a zone and
        // how their Sort/ID order interleaves across tables is one rule, and the
        // editor reads it through this same method. A second copy would let the
        // front end and the editor drift apart.
        $roots = Injector::inst()
            ->get(GridElementRepositoryInterface::class)
            ->findByParents([$owner::class => [$ownerId]], $zone);

        return ArrayList::create($roots);
    }

    public function onAfterPopulateDefaults(): void
    {
        $owner = $this->getOwner();

        /** @var bool $useGrid */
        $useGrid = $owner->config()->get('use_grid_by_default');
        $owner->UseGrid = $useGrid;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();

        $fields->removeByName('Sections');
        $fields->removeByName('GridRoots');
        $fields->removeByName('UseGrid');

        /** @var bool $enableToggle */
        $enableToggle = $owner->config()->get('enable_editor_toggle');
        $useGrid = !$enableToggle || (bool) $owner->UseGrid;

        if ($useGrid) {
            $fields->removeByName('Content');
            $fields->insertAfter(
                'MenuTitle',
                GridEditorField::create('GridEditor', (int) $owner->ID, 'main'),
            );
            $insertBefore = 'GridEditor';
        } else {
            $insertBefore = 'Content';
        }

        if ($enableToggle) {
            $fields->insertBefore(
                $insertBefore,
                CheckboxField::create('UseGrid', _t(self::class . '.USE_GRID', 'Use grid on this page'))
                    ->setDescription(_t(self::class . '.USE_GRID_DESCRIPTION', 'Save the page after changing this setting')),
            );
        }
    }
}
