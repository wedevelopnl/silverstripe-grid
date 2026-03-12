<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\GridAdapterInterface;

/**
 * Abstract base for all grid elements (containers and content).
 *
 * Uses a polymorphic has_one (ParentID + ParentClass) so elements can
 * live under any DataObject — a page, a container, or a future zone owner.
 *
 * @property string $Title
 * @property bool $ShowTitle
 * @property 'h1'|'h2'|'h3'|'h4'|'h5'|'h6' $TitleTag
 * @property string $TitleClass
 * @property int $Sort
 * @property string $ExtraClass
 * @property string $Style
 * @property int $ParentID
 * @property string $ParentClass
 * @method DataObject|null Parent()
 * @mixin Versioned
 */
class GridElement extends DataObject
{
    private static string $table_name = 'GridElement';

    private static string $singular_name = 'Grid element';

    private static string $plural_name = 'Grid elements';

    private static bool $enable_custom_title_classes = false;

    /** @var array<string, string> */
    private static array $dependencies = [
        'gridAdapter' => '%$' . GridAdapterInterface::class,
    ];

    public GridAdapterInterface $gridAdapter;

    /** @var array<string, string> */
    private static array $db = [
        'Title' => 'Varchar(255)',
        'ShowTitle' => 'Boolean',
        'TitleTag' => "Enum('h1,h2,h3,h4,h5,h6', 'h2')",
        'TitleClass' => 'Varchar(255)',
        'Sort' => 'Int',
        'ExtraClass' => 'Varchar(255)',
        'Style' => 'Varchar(255)',
    ];

    /** @var array<string, string> */
    private static array $has_one = [
        'Parent' => DataObject::class,
    ];

    /** @var array<string, string> */
    private static array $defaults = [
        'ShowTitle' => '0',
        'TitleTag' => 'h2',
    ];

    /** @var list<class-string> */
    private static array $extensions = [
        Versioned::class,
    ];

    /** @var string */
    private static string $default_sort = '"Sort" ASC';

    /** @var array<string, array<string, string|list<string>>> */
    private static array $indexes = [
        'Sort' => [
            'type' => 'index',
            'columns' => ['Sort'],
        ],
    ];

    #[\Override]
    public function getCMSEditLink(): ?string
    {
        $page = $this->getPage();

        if (!$page instanceof SiteTree) {
            return null;
        }

        return Controller::join_links(
            CMSPageEditController::singleton()->Link('EditForm'),
            $page->ID,
            'field',
            'GridEditor',
            'item',
            $this->ID,
            'edit',
        );
    }

    /** Human-readable element type identifier (e.g., "Section", "Row", "Text"). */
    public function getType(): string
    {
        $name = static::config()->get('singular_name');

        return $name !== '' ? $name : ClassInfo::shortName(static::class);
    }

    /** Anchor-safe identifier for linking within a page. */
    public function getAnchor(): string
    {
        return sprintf('grid-element-%d', $this->ID);
    }

    /** Class name with namespace separators replaced for safe use as identifiers. */
    public function getTypeName(): string
    {
        return str_replace('\\', '-', static::class);
    }

    /** Summary text for CMS grid views. Override in subclasses. */
    public function getSummary(): string
    {
        return '';
    }

    /**
     * Block schema data consumed by the CMS editor React components.
     *
     * @return array<string, mixed>
     */
    public function getBlockSchema(): array
    {
        $schema = [
            'id' => $this->ID,
            'typeName' => $this->getTypeName(),
            'type' => $this->getType(),
            'title' => $this->Title ?? '',
            'summary' => $this->getSummary(),
        ];

        return array_merge($schema, $this->provideBlockSchema());
    }

    /**
     * Extension point for subclasses to add extra block schema fields.
     *
     * @return array<string, mixed>
     */
    protected function provideBlockSchema(): array
    {
        return [];
    }

    /** CSS class for title styling, sourced from the TitleClass DB field. */
    public function getTitleSizeClass(): string
    {
        $class = (string) $this->TitleClass;
        $this->extend('updateTitleSizeClass', $class);

        return $class;
    }

    #[\Override]
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName([
            'Title', 'TitleTag', 'TitleClass', 'ShowTitle',
            'Sort', 'ExtraClass', 'Style',
            'ParentID', 'ParentClass',
            'Zone',
        ]);

        $titleGroup = FieldGroup::create(
            TextField::create('Title', _t(self::class . '.TITLE', 'Title')),
            DropdownField::create(
                'TitleTag',
                _t(self::class . '.TITLE_TAG', 'Title tag'),
                array_combine(
                    ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                    ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                ),
            ),
        );
        $titleGroup->setName('TitleSettings');
        $titleGroup->setTitle(_t(self::class . '.TITLE_SETTINGS', 'Title'));

        if (static::config()->get('enable_custom_title_classes')) {
            /** @var array<string, string> $options */
            $options = $this->gridAdapter->getTitleClassOptions();
            $this->extend('updateTitleClassOptions', $options);

            $titleGroup->push(
                DropdownField::create(
                    'TitleClass',
                    _t(self::class . '.TITLE_CLASS', 'Display as'),
                    $options,
                )->setEmptyString(_t(self::class . '.TITLE_CLASS_DEFAULT', 'Default')),
            );
        }

        $titleGroup->push(
            CheckboxField::create('ShowTitle', _t(self::class . '.SHOW_TITLE', 'Displayed')),
        );

        $mainTab = $fields->findOrMakeTab('Root.Main');
        $mainTab->unshift($titleGroup);

        return $fields;
    }

    /**
     * Walk the Parent chain until reaching a SiteTree (page) or a non-GridElement owner.
     * Returns null if no page is found.
     */
    public function getPage(): ?DataObject
    {
        $parent = $this->Parent();

        if ($parent === null || !$parent->exists()) {
            return null;
        }

        if ($parent instanceof SiteTree) {
            return $parent;
        }

        if ($parent instanceof self) {
            return $parent->getPage();
        }

        // Parent is a non-GridElement DataObject — treat it as the owning "page"
        return $parent;
    }

    /**
     * @param Member|null $member
     * @return bool|null
     */
    public function canView(mixed $member = null): bool|null
    {
        $member = $member ?: Security::getCurrentUser();

        if ($member !== null) {
            $extended = $this->extendedCan(__FUNCTION__, $member);
            if ($extended !== null) {
                return $extended;
            }
        }

        $page = $this->getPage();

        return $page !== null ? (bool) $page->canView($member) : (bool) Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * @param Member|null $member
     * @return bool|null
     */
    public function canEdit(mixed $member = null): bool|null
    {
        $page = $this->getPage();

        return $page !== null ? (bool) $page->canEdit($member) : (bool) Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * @param Member|null $member
     * @return bool|null
     */
    public function canDelete(mixed $member = null): bool|null
    {
        $page = $this->getPage();

        return $page !== null ? (bool) $page->canDelete($member) : (bool) Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * @param Member|null $member
     * @param array<string, mixed> $context
     * @return bool|null
     */
    public function canCreate(mixed $member = null, mixed $context = []): bool|null
    {
        return (bool) Permission::check('CMS_ACCESS', 'any', $member);
    }

    /**
     * Sets Sort to one past the current maximum for this parent
     * when no explicit Sort has been assigned.
     */
    public function ensureSortSet(): void
    {
        if ($this->Sort > 0) {
            return;
        }

        $max = static::get()
            ->filter([
                'ParentID' => $this->ParentID,
                'ParentClass' => $this->ParentClass,
            ])
            ->max('Sort');

        $this->Sort = (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * Assigns a default "{Type} {N}" title when Title is empty.
     *
     * N is the count of same-type siblings under the same parent + 1,
     * producing a stable, DB-persisted title that survives reordering.
     */
    private function ensureDefaultTitle(): void
    {
        if ((string) $this->Title !== '') {
            return;
        }

        $siblingCount = static::get()
            ->filter([
                'ParentID' => $this->ParentID,
                'ParentClass' => $this->ParentClass,
            ])
            ->exclude('ID', $this->ID)
            ->count();

        $this->Title = _t(
            static::class . '.DEFAULT_TITLE',
            '{type} {count}',
            ['type' => $this->getType(), 'count' => $siblingCount + 1],
        );
    }

    #[\Override]
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $this->ensureDefaultTitle();
        $this->ensureSortSet();
    }
}
