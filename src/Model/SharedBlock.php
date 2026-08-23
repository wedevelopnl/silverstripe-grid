<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Model\List\ArrayList;
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
     * Managing a block requires PAGE access, not a library-specific grant: a
     * shared block is page content maintained in one place, so whoever may edit
     * the pages that show it may maintain it — the same authority an ordinary
     * block on a page carries. It is also what makes localising a page able to
     * localise the blocks it places, rather than silently skipping them for an
     * author who holds no library grant.
     *
     * NOT the bare 'CMS_ACCESS': the framework special-cases that code to
     * succeed for ANY CMS_ACCESS_* grant, so checking it would let a member who
     * only reaches, say, the files manager edit or delete shared blocks.
     * {@see SharedBlockAdmin::$required_permission_codes} gates the library
     * SCREEN on the same code, so API and UI agree.
     *
     * The literal is deliberate and must NOT become
     * `'CMS_ACCESS_' . CMSMain::class`. SilverStripe\CMS\Controllers\CMSMain
     * registers its code under the SHORT name in providePermissions(), and
     * declares the same short name in its own $required_permission_codes; the
     * FQCN-suffixed variant is a code nobody is ever granted, so building it
     * that way silently denies every non-admin.
     */
    private const string ADMIN_PERMISSION = 'CMS_ACCESS_CMSMain';

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

    /**
     * Named without labels on purpose: {@see DataObject::summaryFields()}
     * localises a column only while its label still equals its name, so
     * spelling one here would pin the listing to English.
     *
     * @var list<string>
     */
    private static array $summary_fields = [
        'Title',
        'getRootTypeLabel',
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

        // The editor is keyed by the block's id, so a record without one cannot
        // host it. No author arrives here: blocks are created already seeded and
        // SharedBlockItemRequest refuses the unsaved form. The singleton is not
        // in the database either, and it is what scaffolding asks for fields.
        if (!$this->isInDB()) {
            return $fields;
        }

        /** @var positive-int $blockId */
        $blockId = (int) $this->ID;

        $fields->addFieldToTab('Root.Main', GridEditorField::forSharedBlock('BlockEditor', $blockId));
        $fields->addFieldToTab('Root.Usage', $this->buildUsageField());

        return $fields;
    }

    /**
     * The pages placing this block, each linking into the CMS.
     *
     * A GridField rather than assembled markup: sorting, pagination and the
     * casting layer's escaping all come from the framework, and the one column
     * that builds a link builds it through `setFieldFormatting` — the same seam
     * {@see \WeDevelop\Grid\Reports\GridElementReport} uses for its own.
     */
    private function buildUsageField(): GridField
    {
        $resolver = Injector::inst()->get(SharedBlockUsageResolver::class);

        /** @var ArrayList<DataObject> $pages */
        $pages = ArrayList::create($resolver->pagesUsing($this));

        $config = GridFieldConfig_Base::create();

        // A search box over one block's usage is noise, and the header
        // scaffolds its filters from the model's search context — which a list
        // assembled in PHP has no query to apply them to.
        $config->removeComponentsByType(GridFieldFilterHeader::class);

        $config->getComponentByType(GridFieldDataColumns::class)
            ?->setDisplayFields(['Title' => _t(self::class . '.USAGE_PAGE', 'Page')])
            ->setFieldFormatting([
                // $value arrives cast and escaped — GridFieldDataColumns types
                // it a string and runs castValue() before us; only the href is
                // ours to escape.
                'Title' => static function (string $value, DataObject $item): string {
                    $link = $item instanceof SiteTree ? (string) $item->getCMSEditLink() : '';

                    return $link === ''
                        ? $value
                        : sprintf('<a href="%s">%s</a>', Convert::raw2att($link), $value);
                },
            ]);

        $field = GridField::create(
            'UsedOn',
            _t(self::class . '.USED_ON', 'Used on'),
            $pages,
            $config,
        );

        // An empty list has no first record to infer the model from, and the
        // sortable header asks for one on every render.
        $field->setModelClass(SiteTree::class);

        if ($pages->count() === 0) {
            $field->setDescription(
                _t(self::class . '.NOT_USED', 'This block is not placed on any page yet.'),
            );
        }

        return $field;
    }

    /**
     * `getRootTypeLabel` is a method, and the framework's automatic labels cover
     * $db fields only — without an entry here the listing header would fall
     * back to the raw method name.
     *
     * @param bool $includerelations
     * @return array<string, string>
     */
    #[Override]
    public function fieldLabels(mixed $includerelations = true): array
    {
        /** @var array<string, string> $labels */
        $labels = parent::fieldLabels($includerelations);
        $labels['getRootTypeLabel'] = _t(self::class . '.ROOT_TYPE', 'Type');

        return $labels;
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
     * seeing what is in the library is not editing it, and a picker may be
     * rendered in contexts narrower than page editing. Narrow it per project
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
