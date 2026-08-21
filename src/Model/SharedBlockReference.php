<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/**
 * A placement of a SharedBlock inside a page's grid. Carries only placement
 * data (Parent, Sort, Zone); all content lives on the block's own subtree.
 *
 * Deliberately declares no $owns to Block: page publish must never cascade into
 * the shared source, which has its own lifecycle.
 *
 * @property string $Zone
 * @property int $BlockID
 * @method SharedBlock|null Block()
 */
class SharedBlockReference extends GridElement
{
    private static string $table_name = 'WeDevelop_Grid_SharedBlockReference';

    private static string $singular_name = 'Shared block';

    private static string $plural_name = 'Shared blocks';

    private static string $icon = 'font-icon-block-layout';

    private static string $class_description = 'A reusable block maintained once in the shared library';

    /** @var array<string, string> */
    private static array $db = [
        'Zone' => 'Varchar(50)',
    ];

    /** @var array<string, class-string> */
    private static array $has_one = [
        'Block' => SharedBlock::class,
    ];

    /** @var array<string, array<string, string|list<string>>> */
    private static array $indexes = [
        'Zone' => [
            'type' => 'index',
            'columns' => ['Zone'],
        ],
    ];

    /**
     * The class placement rules must judge this element by: a reference stands
     * in for its block's root, so a row-rooted block may only be placed where a
     * Row may live. Null when the block is missing or still empty — no
     * placement is valid then.
     *
     * @return class-string<GridElement>|null
     */
    public function getEffectiveRootClass(): ?string
    {
        if ((int) $this->BlockID <= 0) {
            return null;
        }

        $blockId = (int) $this->BlockID;

        // Pinned to DRAFT on purpose. A block's SHAPE is a structural fact that
        // exists as soon as an author builds it; whether it is published is a
        // separate question answered by the status badge and by rendering.
        // Resolving on the ambient stage would make publishing a page fail
        // validation whenever its block had not been published yet — the LIVE
        // write during publish would see an empty block.
        return Versioned::withVersionedMode(static function () use ($blockId): ?string {
            Versioned::set_stage(Versioned::DRAFT);

            $block = SharedBlock::get()->byID($blockId);
            $root = $block?->getRootElement();

            if ($root === null) {
                return null;
            }

            /** @var class-string<GridElement> $rootClass */
            $rootClass = $root->ClassName;

            return $rootClass;
        });
    }

    /**
     * Render the block's root element directly, with no wrapper of our own.
     *
     * The holder templates, adapter classes and data-element attributes all
     * come from the real shared elements, so the DOM is byte-identical to the
     * same subtree placed locally and every grid adapter works unchanged.
     *
     * Unlike {@see getEffectiveRootClass()} this reads the CURRENT stage, so
     * live only ever renders a published subtree. Every failure mode — missing
     * block, nothing published yet, no content in this locale — renders empty
     * and logs, because raw DB state must never 500 a page. Recursion cannot
     * occur: nesting is refused at write time.
     */
    #[Override]
    public function forTemplate(): string
    {
        $block = $this->Block();
        $root = $block !== null && $block->exists() ? $block->getRootElement() : null;

        if ($root === null) {
            Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
                'Shared block reference #%d has no renderable content (block #%d).',
                (int) $this->ID,
                (int) $this->BlockID,
            ));

            return '';
        }

        return $root->forTemplate();
    }
}
