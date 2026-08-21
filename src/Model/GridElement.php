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
    /**
     * The element classes that may sit directly under a page, each carrying its
     * own Zone column.
     *
     * Enumerated once because every consumer must cover ALL of them: a zone's
     * Sort sequence spans the whole set, and any read of a page's root elements
     * that misses one silently omits content. `Section::get()` at page root is
     * nearly always a bug — see {@see OrmGridElementRepository::findByParents()}
     * and {@see GridPageExtension::GridZone()}.
     *
     * @var list<class-string<GridElement>>
     */
    public const array ROOT_ELEMENT_CLASSES = [
        Section::class,
        SharedBlockReference::class,
    ];

    private static string $table_name = 'WeDevelop_Grid_GridElement';

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

    /**
     * Declares this element's owner so versioned publish/ownership traversal can
     * locate it. The polymorphic 'Parent' has_one is otherwise skipped by
     * RecursivePublishable's reverse-owner lookup (it guards out relations declared
     * as DataObject::class); the explicit $owned_by forces the framework to follow
     * Parent() via ParentClass.
     *
     * @var list<string>
     */
    private static array $owned_by = [
        'Parent',
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

    /**
     * Covers the module's hottest access path — every child fetch, sort
     * assignment, default-title count and scaffold re-check filters on
     * (ParentClass, ParentID) and orders by Sort. Equality columns lead, the
     * ORDER BY column trails, so the index serves both the WHERE and the sort
     * in one read. Section's zone-scoped variant additionally filters Zone,
     * which lives on the Section subclass table and so cannot join this
     * base-table index — Section keeps its own single-column Zone index.
     *
     * @var array<string, array<string, string|list<string>>>
     */
    private static array $indexes = [
        'ParentSort' => [
            'type' => 'index',
            'columns' => ['ParentClass', 'ParentID', 'Sort'],
        ],
    ];

    /** Render through the holder template (two-pass: holder wraps inner content). */
    #[Override]
    public function forTemplate(): string
    {
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

        // Content inside a shared block is edited in the library, through the
        // block's own nested grid editor rather than a page's.
        if ($page instanceof SharedBlock) {
            return $this->buildSharedBlockEditLink($page);
        }

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

    /**
     * The element sits one level deeper than the block's own edit form, inside
     * the GridEditorField that form hosts — hence the second `field/item` walk
     * on top of {@see SharedBlock::cmsItemLink()}.
     */
    private function buildSharedBlockEditLink(SharedBlock $block): string
    {
        return Controller::join_links(
            SharedBlock::cmsItemLink((int) $block->ID),
            'ItemEditForm',
            'field',
            'BlockEditor',
            'item',
            (string) $this->ID,
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
        $this->beforeUpdateCMSFields(function (FieldList $fields): void {
            $fields->removeByName([
                'Title', 'TitleTag', 'TitleClass', 'ShowTitle',
                'Sort', 'ExtraClass', 'Style',
                'ParentID', 'ParentClass',
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
        });

        return parent::getCMSFields();
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

        if ($parent instanceof self) {
            return $parent->getPage();
        }

        // Parent is a SiteTree page or any other non-GridElement DataObject —
        // treat it as the owning "page" and return it directly.
        return $parent;
    }

    /**
     * @param Member|null $member
     */
    #[Override]
    public function canView(mixed $member = null): bool
    {
        $member = $member ?: Security::getCurrentUser();

        // extendedCan expects a non-null member; resolve the current user first
        // and let extensions veto/grant before delegating to the owning page.
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
        $member = $member ?: Security::getCurrentUser();

        if ($member !== null) {
            $extended = $this->extendedCan(__FUNCTION__, $member);
            if ($extended !== null) {
                return $extended;
            }
        }

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
        // A block owns exactly one subtree, and this element IS that subtree.
        // Removing it leaves a block that resolves no effective root class:
        // every page placing it renders nothing, and later grid writes on those
        // pages fail with BLOCK_EMPTY. The block is removed as a whole instead
        // ({@see \WeDevelop\Grid\Extensions\SharedBlockDeleteActionExtension}),
        // which is why this sits ABOVE extendedCan — it is a structural
        // invariant, not a permission an extension may grant.
        //
        // The framework's own removal paths are unaffected: DataObject::delete()
        // and doArchive() check no permission, and $cascade_deletes reaches this
        // element through onBeforeDelete() when the block itself is deleted.
        if ($this->Parent() instanceof SharedBlock) {
            return false;
        }

        $member = $member ?: Security::getCurrentUser();

        if ($member !== null) {
            $extended = $this->extendedCan(__FUNCTION__, $member);
            if ($extended !== null) {
                return $extended;
            }
        }

        $page = $this->getPage();

        // canEdit(), never canDelete(): removing an element from a shared block
        // is an EDIT of the block, not a deletion of it. The two answers only
        // diverge once a project vetoes the block's own deletion — and such a
        // veto must protect the library record, not freeze its contents.
        // ({@see SharedBlock::canDelete()} itself does not veto on usage.)
        if ($page instanceof SharedBlock) {
            return $page->canEdit($member);
        }

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
        $member = $member ?: Security::getCurrentUser();

        if ($member !== null) {
            $extended = $this->extendedCan(__FUNCTION__, $member, $context);
            if ($extended !== null) {
                return $extended;
            }
        }

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

        $filter = [
            'ParentID' => $this->ParentID,
            'ParentClass' => $this->ParentClass,
        ];

        if ($this->isZoneScoped()) {
            // At page root each zone is its own Sort sequence, and the siblings
            // sharing it are spread over one table per root class. Zone is a
            // subclass column, so the base list cannot filter it — ask every
            // root class for its own max and take the highest.
            $filter['Zone'] = $this->getZoneValue();

            $max = 0;
            foreach (self::ROOT_ELEMENT_CLASSES as $rootClass) {
                $classMax = DataObject::get($rootClass)->filter($filter)->max('Sort');
                $max = max($max, is_numeric($classMax) ? (int) $classMax : 0);
            }

            $this->Sort = $max + 1;

            return;
        }

        // Query the shared GridElement base list, NOT static::get(): late static
        // binding would scope the max to this element's concrete subclass, but Sort
        // is one sequence across ALL element classes under a parent (a Column holds
        // mixed content types). Scoping per-class would assign a colliding Sort when
        // the first element of a new type is added, dropping it mid-list.
        $max = GridElement::get()
            ->filter($filter)
            ->max('Sort');

        $this->Sort = (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * True when this element sits directly under a PAGE — the only position
     * where Zone applies.
     *
     * Matched to {@see \WeDevelop\Grid\Repository\OrmGridElementRepository::findByParents()}:
     * a block-rooted element is not zone-scoped either, even though its parent
     * is not a grid element, because a block holds one unzoned subtree.
     */
    protected function isZoneScoped(): bool
    {
        return $this->ParentClass !== '' && is_a($this->ParentClass, SiteTree::class, true);
    }

    /**
     * This element's Zone, or '' when its class carries no Zone column.
     *
     * Read through getField() rather than ->Zone: Zone is declared on the root
     * subclasses ({@see self::ROOT_ELEMENT_CLASSES}), not on this base class.
     */
    protected function getZoneValue(): string
    {
        $zone = $this->getField('Zone');

        return is_string($zone) ? $zone : '';
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

    /**
     * The element's CMS-facing display label: the stored Title, or a
     * translatable "(untitled)" fallback when empty.
     *
     * Read-time counterpart of {@see ensureDefaultTitle()}, which assigns a
     * persistent default at write time — this covers unwritten elements and
     * rows predating the write-time default.
     *
     * @return non-empty-string
     */
    public function getDisplayTitle(): string
    {
        /** @var non-empty-string $title '(untitled)' fallback guarantees non-empty */
        $title = $this->Title ?: _t(
            self::class . '.UNTITLED',
            '(untitled)',
        );

        return $title;
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
            // Re-query with a fresh ORM query (not $this->getChildren(), which
            // can return an eager-loaded/relation-cached stale list) so the
            // check reflects committed state inside the transaction.
            //
            // Counted on the GridElement base list, NOT on $childClass: a
            // shared block placed here stands in for the child class and is a
            // child by every rule that matters, but it is a
            // SharedBlockReference row. Counting per-class made a container
            // holding only a placement scaffold a phantom empty child on its
            // next write.
            $existing = GridElement::get()
                ->filter([
                    'ParentID' => $this->ID,
                    'ParentClass' => static::class,
                ])
                ->count();

            if ($existing > 0) {
                return;
            }

            $child = $childClass::create();
            $child->ParentID = $this->ID;
            $child->ParentClass = static::class;
            $child->write();
        });
    }
}
