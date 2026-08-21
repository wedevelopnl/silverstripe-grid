<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Admin\SharedBlockAdmin;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Service\SharedBlockUsageResolver;

/**
 * Library record for a reusable grid subtree. The subtree hangs off this
 * record through GridElement's polymorphic Parent relation, so publishing the
 * block cascades down the normal Section > Row > Column ownership chain.
 *
 * @property string $Title
 * @method HasManyList<GridElement> RootElements()
 * @mixin Versioned
 */
class SharedBlock extends DataObject
{
    /**
     * The library's own CMS section code — what managing a block actually
     * requires.
     *
     * NOT the bare 'CMS_ACCESS': the framework special-cases that code to
     * succeed for ANY CMS_ACCESS_* grant, so checking it would let a member who
     * only reaches, say, the files manager edit or delete shared blocks through
     * the API while {@see SharedBlockAdmin} refused to even open for them.
     */
    private const string ADMIN_PERMISSION = 'CMS_ACCESS_' . SharedBlockAdmin::class;

    private static string $table_name = 'WeDevelop_Grid_SharedBlock';

    private static string $singular_name = 'Shared block';

    private static string $plural_name = 'Shared blocks';

    /** @var array<string, string> */
    private static array $db = [
        'Title' => 'Varchar(255)',
    ];

    /** @var array<string, string> */
    private static array $has_many = [
        'RootElements' => GridElement::class . '.Parent',
    ];

    /** @var list<string> */
    private static array $owns = [
        'RootElements',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'RootElements',
    ];

    /** @var list<string> */
    private static array $cascade_duplicates = [
        'RootElements',
    ];

    /** @var list<class-string> */
    private static array $extensions = [
        Versioned::class,
    ];

    /** @var array<string, string> */
    private static array $summary_fields = [
        'Title' => 'Title',
        'getRootTypeLabel' => 'Type',
    ];

    private static string $default_sort = '"Title" ASC';

    /**
     * Report-only computed columns ({@see \WeDevelop\Grid\Reports\SharedBlockReport}).
     *
     * @var string|null
     */
    public $RootTypeLabel;

    /** @var int|null */
    public $UsageCount;

    /** @var string|null */
    public $StatusLabel;

    /**
     * Assigns a numbered default title when Title is empty, so a block created
     * straight from the library's add button is identifiable in the listing
     * before the author renames it. Mirrors
     * {@see GridElement::ensureDefaultTitle()}, including its known property:
     * the count is of existing rows, so deletions can recycle a number.
     */
    #[Override]
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if ((string) $this->Title !== '') {
            return;
        }

        $blockCount = self::get()->exclude(['ID' => $this->ID])->count();

        $this->Title = _t(
            self::class . '.DEFAULT_TITLE',
            'New shared block {count}',
            ['count' => $blockCount + 1],
        );
    }

    #[Override]
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName('RootElements');

        if (!$this->isInDB()) {
            // The editor is keyed by the block's id, so the record must exist
            // before it can host one. Saving once reveals it.
            $fields->addFieldToTab(
                'Root.Main',
                LiteralField::create(
                    'BlockEditorHint',
                    '<p class="message notice">' . _t(
                        self::class . '.SAVE_FIRST',
                        'Save this block to start adding content to it.',
                    ) . '</p>',
                ),
            );

            return $fields;
        }

        /** @var positive-int $blockId */
        $blockId = (int) $this->ID;

        $fields->addFieldToTab('Root.Main', GridEditorField::forSharedBlock('BlockEditor', $blockId));
        $fields->addFieldToTab('Root.Usage', $this->buildUsageField());

        return $fields;
    }

    /** Read-only list of the pages placing this block, each linking into the CMS. */
    private function buildUsageField(): LiteralField
    {
        $resolver = Injector::inst()->get(SharedBlockUsageResolver::class);
        $pages = $resolver->pagesUsing($this);

        if ($pages === []) {
            return LiteralField::create(
                'UsedOn',
                '<p>' . _t(self::class . '.NOT_USED', 'This block is not placed on any page yet.') . '</p>',
            );
        }

        $items = '';
        foreach ($pages as $page) {
            $title = (string) $page->getTitle();
            $link = $page instanceof SiteTree ? (string) $page->getCMSEditLink() : '';

            $items .= $link === ''
                ? sprintf('<li>%s</li>', htmlspecialchars($title, ENT_QUOTES))
                : sprintf(
                    '<li><a href="%s">%s</a></li>',
                    htmlspecialchars($link, ENT_QUOTES),
                    htmlspecialchars($title, ENT_QUOTES),
                );
        }

        return LiteralField::create('UsedOn', '<ul class="grid-shared-block__usage">' . $items . '</ul>');
    }

    #[Override]
    public function getCMSEditLink(): ?string
    {
        if ((int) $this->ID <= 0) {
            return null;
        }

        return Controller::join_links(self::cmsItemLink((int) $this->ID), 'edit');
    }

    /**
     * ModelAdmin nests its item URLs as
     * `{admin}/{sanitisedClass}/EditForm/field/{sanitisedClass}/item/{id}`;
     * `sanitiseClassName` turns backslashes into dashes. The trailing action
     * segment is the caller's: `edit` for the block's own form, or the deeper
     * `ItemEditForm/field/BlockEditor/...` walk {@see GridElement} uses to
     * reach an element inside the block's grid editor.
     *
     * SharedBlockAdminTest asserts a GET on the generated URL actually resolves
     * — this shape is verified against the running CMS, not assumed.
     */
    public static function cmsItemLink(int $blockId): string
    {
        $sanitisedClass = str_replace('\\', '-', self::class);

        return Controller::join_links(
            Director::baseURL(),
            SharedBlockAdmin::singleton()->Link($sanitisedClass),
            'EditForm',
            'field',
            $sanitisedClass,
            'item',
            (string) $blockId,
        );
    }

    /**
     * Structurally a has_many; exactly one root by construction, since every
     * creation path (place, convert, library root add) writes a single root.
     */
    public function getRootElement(): ?GridElement
    {
        if ((int) $this->ID <= 0) {
            return null;
        }

        return $this->RootElements()->first();
    }

    /**
     * The type of element rooting this block, named after the concrete class
     * ("Section", "Row", "Text") exactly as the editor's cards name it.
     *
     * It is the fact that decides where the block may be placed, so both the
     * library listing and the report carry it as a column.
     */
    public function getRootTypeLabel(): string
    {
        return $this->getRootElement()?->getType()
            ?? _t(self::class . '.EMPTY_ROOT', 'Empty');
    }

    /**
     * Whether any page still places this block, on either stage. A live-only
     * placement counts: unpublishing a consuming page does not stop it from
     * consuming the block.
     */
    public function isReferenced(): bool
    {
        if ((int) $this->ID <= 0) {
            return false;
        }

        if (SharedBlockReference::get()->filter(['BlockID' => $this->ID])->exists()) {
            return true;
        }

        return Versioned::withVersionedMode(function (): bool {
            Versioned::set_stage(Versioned::LIVE);

            return SharedBlockReference::get()->filter(['BlockID' => $this->ID])->exists();
        });
    }

    /**
     * Deliberately the broad CMS gate rather than {@see self::ADMIN_PERMISSION}:
     * placing an existing block is a page-editing act, so an author who may
     * edit pages must be able to see the library's contents in the picker
     * without also being granted the library section. Narrow it per project
     * with an updateCanView extension.
     *
     * @param Member|null $member
     */
    #[Override]
    public function canView(mixed $member = null): bool
    {
        return $this->checkPermission(__FUNCTION__, $member, 'CMS_ACCESS');
    }

    /**
     * @param Member|null $member
     */
    #[Override]
    public function canEdit(mixed $member = null): bool
    {
        return $this->checkPermission(__FUNCTION__, $member, self::ADMIN_PERMISSION);
    }

    /**
     * @param Member|null $member
     * @param array<string, mixed> $context
     */
    #[Override]
    public function canCreate(mixed $member = null, mixed $context = []): bool
    {
        /** @var array<string, mixed> $context */
        return $this->checkPermission(__FUNCTION__, $member, self::ADMIN_PERMISSION, $context);
    }

    /**
     * Being placed is deliberately NOT a veto. Refusing the delete would oblige
     * the author to visit every consuming page by hand first, an unbounded
     * chore the library offers no tooling for; the informed confirmation in
     * {@see \WeDevelop\Grid\Value\SharedBlockDeleteMode} carries that weight
     * instead, exactly as the unpublish flow already does.
     *
     * @param Member|null $member
     */
    #[Override]
    public function canDelete(mixed $member = null): bool
    {
        return $this->checkPermission(__FUNCTION__, $member, self::ADMIN_PERMISSION);
    }

    /**
     * Placements are page content, so no ownership config on this record
     * reaches them and a delete would strand every reference. A stranded
     * reference does more than render empty: it resolves no effective root
     * class, which fails every later write on that page's grid with
     * BLOCK_EMPTY. Cleaning up here rather than in
     * {@see \WeDevelop\Grid\Service\SharedBlockService::delete()} holds the
     * invariant on every delete path — the CMS action, a dev task, a bare
     * delete() in project code.
     *
     * The unshare path detaches its placements before deleting, so by the time
     * this runs there is nothing left for it to find.
     */
    #[Override]
    protected function onBeforeDelete(): void
    {
        parent::onBeforeDelete();

        $blockId = (int) $this->ID;

        if ($blockId <= 0) {
            return;
        }

        foreach (SharedBlockReference::get()->filter(['BlockID' => $blockId]) as $reference) {
            $reference->doArchive();
        }

        // A placement survives on live alone when its draft row was deleted
        // without unpublishing first, and doArchive() above never sees those.
        Versioned::withVersionedMode(static function () use ($blockId): void {
            Versioned::set_stage(Versioned::LIVE);

            foreach (SharedBlockReference::get()->filter(['BlockID' => $blockId]) as $reference) {
                $reference->deleteFromStage(Versioned::LIVE);
            }
        });
    }

    /**
     * @param Member|null $member
     * @param array<string, mixed> $context
     */
    private function checkPermission(
        string $permissionMethod,
        mixed $member,
        string $code,
        array $context = [],
    ): bool {
        $member = $member ?: Security::getCurrentUser();

        if ($member !== null) {
            $extended = $this->extendedCan($permissionMethod, $member, $context);
            if ($extended !== null) {
                return $extended;
            }
        }

        $result = Permission::check($code, 'any', $member);
        assert(is_bool($result));

        return $result;
    }
}
