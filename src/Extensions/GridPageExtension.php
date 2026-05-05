<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Forms\GridEditorField;

/**
 * Adds grid editing capability to SiteTree pages.
 *
 * Provides the has_many relationship for top-level Sections and
 * injects the GridEditorField into the CMS editing form. A per-page
 * toggle allows switching between the grid editor and the default
 * Content HTMLEditorField.
 *
 * @extends Extension<SiteTree>
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

    /** @var array<string, string> */
    private static array $has_many = [
        'Sections' => Section::class . '.Parent',
    ];

    /** @var list<string> */
    private static array $owns = [
        'Sections',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'Sections',
    ];

    /** @var list<string> */
    private static array $cascade_duplicates = [
        'Sections',
    ];

    public function onAfterPopulateDefaults(): void
    {
        /** @var SiteTree $owner */
        $owner = $this->getOwner();

        /** @var bool $useGrid */
        $useGrid = $owner->config()->get('use_grid_by_default');
        $owner->UseGrid = $useGrid;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        /** @var SiteTree $owner */
        $owner = $this->getOwner();

        $fields->removeByName('Sections');
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
