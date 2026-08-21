<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\E2E\Support;

use SilverStripe\Versioned\Versioned;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;

/**
 * Extends the module's fixture reset to cover the shared block library.
 *
 * The module resets by archiving SiteTree pages whose URLSegment carries the
 * E2E prefix. A SharedBlock is a library record with no page and no URL, so
 * nothing ever collected one: every fixture load and every spec that converted
 * an element left another block behind, and a dev database ended up listing
 * dozens of identical blocks in every picker.
 *
 * Only blocks that nothing places any more are swept. After the pages are gone
 * that is every block a fixture or a spec created, while a block a developer
 * placed on a page of their own still has a reference and survives.
 *
 * Lives here rather than in src/ because it extends a DEV dependency: a class in
 * the published tree whose parent is absent in production would break the class
 * manifest for consumers. `_config/dev.yml` binds it through Injector, so
 * `FixtureLoader::create()` in the module's controller returns this subclass.
 */
class GridFixtureLoader extends FixtureLoader
{
    public function reset(): void
    {
        // Pages first: archiving them removes the placements, which is what
        // makes the blocks they referenced collectable below.
        parent::reset();
        $this->purgeOrphanedReferences();

        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);

            foreach (SharedBlock::get() as $block) {
                // isReferenced() reads both stages, so a block still placed by
                // an unpublished page is kept.
                if ($block->isReferenced()) {
                    continue;
                }

                // Clears both stages; $cascade_deletes carries the block's own
                // subtree with it.
                $block->doArchive();
            }
        });
    }

    /**
     * Drop placements whose parent no longer exists, on either stage.
     *
     * Archiving a page leaves its placement rows behind, and `isReferenced()`
     * counts one of those as a placement — so without this the sweep above keeps
     * every block that any past run ever placed, which is all of them. Debris,
     * not content: a reference exists only to say where a block sits on a page,
     * so one with no page says nothing.
     */
    private function purgeOrphanedReferences(): void
    {
        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            Versioned::withVersionedMode(static function () use ($stage): void {
                Versioned::set_stage($stage);

                foreach (SharedBlockReference::get() as $reference) {
                    $parent = $reference->Parent();
                    if ($parent !== null && $parent->exists()) {
                        continue;
                    }

                    $reference->deleteFromStage($stage);
                }
            });
        }
    }
}
