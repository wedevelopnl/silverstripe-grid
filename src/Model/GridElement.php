<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\VersionedAdmin\Forms\HistoryViewerField;
use WeDevelop\Grid\Contract\ContainerInterface;
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

    private static string $default_sort = '"Sort" ASC';

    /** @var array<string, array<string, string|list<string>>> */
    private static array $indexes = [
        'Sort' => [
            'type' => 'index',
            'columns' => ['Sort'],
        ],
    ];

    /** Render through the holder template (two-pass: holder wraps inner content). */
    #[Override]
    public function forTemplate(): string
    {
        /** @var DBHTMLText $result */
        $result = $this->renderWith($this->getViewerTemplates('_holder'));

        return (string) $result;
    }

    /** Inner content rendered by `$Element` in holder templates. */
    public function Element(): DBHTMLText
    {
        return $this->renderWith($this->getViewerTemplates());
    }

    /**
     * Holder-level CSS classes: ExtraClass + Style + element-specific classes.
     *
     * Subclasses should override {@see provideHolderClasses()} to add
     * element-specific classes (e.g., row or column grid classes).
     */
    public function getHolderClasses(): string
    {
        $parts = array_filter([
            (string) $this->Style,
            (string) $this->ExtraClass,
            ...$this->provideHolderClasses(),
        ], static fn(string $part): bool => $part !== '');

        $classes = implode(' ', $parts);
        $this->extend('updateHolderClasses', $classes);

        return $classes;
    }

    /**
     * Extension point for subclasses to contribute element-specific CSS classes.
     *
     * @return list<string>
     */
    protected function provideHolderClasses(): array
    {
        return [];
    }

    /** Short class name for CSS class generation in holder templates. */
    public function getSimpleClassName(): string
    {
        return ClassInfo::shortName(static::class);
    }

    #[Override]
    public function getCMSEditLink(): ?string
    {
        $page = $this->getPage();

        if (!$page instanceof SiteTree) {
            return null;
        }

        return Controller::join_links(
            Director::baseURL(),
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

    /**
     * Short, plain-text description of this element's content for the CMS editor card.
     *
     * Default: null — no summary rendered. Override in content-element subclasses
     * to return a short descriptor (strip HTML and truncate as needed).
     * An empty string is treated identically to null.
     *
     * @example
     *   public function getSummary(): ?string
     *   {
     *       return $this->dbObject('HTML')->Summary(20);
     *   }
     */
    public function getSummary(): ?string
    {
        return null;
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

    #[Override]
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

        if ($this->isInDB()) {
            $fields->addFieldToTab(
                'Root.History',
                HistoryViewerField::create('ElementHistory'),
            );
        }

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
     */
    #[Override]
    public function canView(mixed $member = null): bool
    {
        $member = $member ?: Security::getCurrentUser();

        if ($member !== null) {
            $extended = $this->extendedCan(__FUNCTION__, $member);
            if ($extended !== null) {
                return $extended;
            }
        }

        $page = $this->getPage();

        if ($page instanceof DataObject) {
            return $page->canView($member);
        }

        $result = Permission::check('CMS_ACCESS', 'any', $member);
        assert(is_bool($result));

        return $result;
    }

    /**
     * @param Member|null $member
     */
    #[Override]
    public function canEdit(mixed $member = null): bool
    {
        $page = $this->getPage();

        if ($page instanceof DataObject) {
            return $page->canEdit($member);
        }

        $result = Permission::check('CMS_ACCESS', 'any', $member);
        assert(is_bool($result));

        return $result;
    }

    /**
     * @param Member|null $member
     */
    #[Override]
    public function canDelete(mixed $member = null): bool
    {
        $page = $this->getPage();

        if ($page instanceof DataObject) {
            return $page->canDelete($member);
        }

        $result = Permission::check('CMS_ACCESS', 'any', $member);
        assert(is_bool($result));

        return $result;
    }

    /**
     * @param Member|null $member
     * @param array<string, mixed> $context
     */
    #[Override]
    public function canCreate(mixed $member = null, mixed $context = []): bool
    {
        $result = Permission::check('CMS_ACCESS', 'any', $member);
        assert(is_bool($result));

        return $result;
    }

    /**
     * Sets Sort to one past the current maximum for this parent
     * when no explicit Sort has been assigned.
     */
    protected function ensureSortSet(): void
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
            ])->exclude(['ID' => $this->ID])
            ->count();

        $this->Title = _t(
            static::class . '.DEFAULT_TITLE',
            '{type} {count}',
            ['type' => $this->getType(), 'count' => $siblingCount + 1],
        );
    }

    #[Override]
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $this->ensureDefaultTitle();
        $this->ensureSortSet();
    }

    /**
     * Auto-scaffold the first child of the container type's allowed child
     * class when none exists. A no-op on non-containers, on non-draft writes,
     * and on container types with no allowed child (Column).
     *
     * The scaffolded child's title is left empty — {@see ensureDefaultTitle}
     * in the child's own onBeforeWrite hook produces an auto-numbered title
     * like "Row 1" or "Column 1".
     */
    #[Override]
    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        if (!$this instanceof ContainerInterface) {
            return;
        }

        if (!static::config()->get('auto_scaffold')) {
            return;
        }

        if (Versioned::get_stage() !== Versioned::DRAFT) {
            return;
        }

        $childClass = $this->getContainerType()->allowedChildClass();
        if ($childClass === null) {
            return;
        }

        $conn = DB::get_conn();
        if ($conn === null) {
            return;
        }

        // Wrap the check-then-create in a transaction and re-check inside the
        // closure so concurrent writes cannot race past the guard (TOCTOU).
        $conn->withTransaction(function () use ($childClass): void {
            if ($this->getChildren()->count() > 0) {
                return;
            }

            $child = $childClass::create();
            $child->ParentID = $this->ID;
            $child->ParentClass = static::class;
            $child->write();
        });
    }
}
