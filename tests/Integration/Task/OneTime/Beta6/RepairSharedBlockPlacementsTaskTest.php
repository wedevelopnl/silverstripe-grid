<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Task\OneTime\Beta6;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Task\OneTime\Beta6\RepairSharedBlockPlacementsTask;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\StrandsSharedBlockPlacements;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * Releases 6.0.0-beta.4 and beta.5 deleted a shared block in the active locale
 * only, leaving placements that point at a block record that no longer exists.
 * Each test builds a valid placement through the ORM and then removes its block
 * with raw SQL, the only way left to reach that state.
 */
#[CoversClass(RepairSharedBlockPlacementsTask::class)]
final class RepairSharedBlockPlacementsTaskTest extends SapphireTest
{
    use DisablesAutoScaffolding;
    use StrandsSharedBlockPlacements;

    protected static $fixture_file = __DIR__ . '/../../../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    /** @return array{exitCode: int, output: string} */
    private function runTask(bool $dryRun = false): array
    {
        return TaskRunner::run(RepairSharedBlockPlacementsTask::singleton(), $dryRun ? ['--dry-run' => true] : []);
    }

    private function page(string $name = 'test_page'): Page
    {
        return $this->objFromFixture(Page::class, $name);
    }

    /** A block with a one-section subtree, placed on $page and published with it. */
    private function publishedPlacement(Page $page): SharedBlockReference
    {
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '', title: 'Shared section');
        $reference = GridTreeFactory::reference($page, $block);

        $block->publishRecursive();
        $page->publishRecursive();

        return $reference;
    }

    public function testSucceedsWithNothingToRepair(): void
    {
        $this->publishedPlacement($this->page());
        $before = $this->snapshot();

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame($before, $this->snapshot());
        self::assertSame(
            "Nothing to repair: no placement of a deleted shared block is left.\n",
            $result['output'],
        );
    }

    public function testRemovesAStrandedPlacementFromDraftLiveAndHistory(): void
    {
        $page = $this->page();
        $reference = $this->publishedPlacement($page);
        $section = GridTreeFactory::section($page, title: 'Page content');
        $this->strand($reference->Block());

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame(0, $this->storedRows((int) $reference->ID), 'no row of the placement is left on any stage');
        self::assertNotNull(GridElement::get()->byID($section->ID), 'the page\'s own content is untouched');
        self::assertStringContainsString(sprintf(
            "Leftover placements of deleted shared blocks:\n  Integration Test Page (page %d) draft and published\n"
            . "1 placement on 1 page removed. Their pages show visitors the same as before. Ask an editor to check them.\n",
            $page->ID,
        ), $result['output']);
    }

    public function testRemovesADraftOnlyAndALiveOnlyPlacementOnTwoPages(): void
    {
        $draftPage = $this->page();
        $livePage = $this->page('test_page_2');

        $block = GridTreeFactory::sharedBlock();
        $draftOnly = GridTreeFactory::reference($draftPage, $block);
        $liveOnly = GridTreeFactory::reference($livePage, $block);
        $liveOnly->publishSingle();
        $this->removeFromStage((int) $liveOnly->ID, '');
        $this->strand($block);

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame(0, $this->storedRows((int) $draftOnly->ID));
        self::assertSame(0, $this->storedRows((int) $liveOnly->ID));
        self::assertStringContainsString(sprintf(
            "  Integration Test Page (page %d) draft\n  Integration Test Page 2 (page %d) published\n"
            . '2 placements on 2 pages removed.',
            $draftPage->ID,
            $livePage->ID,
        ), $result['output']);
    }

    public function testLeavesTheDeletedBlocksOrphanedContentAlone(): void
    {
        // That content is what an earlier "keep a copy" lost; nothing records
        // which mode was chosen, so it is never the repair's to remove.
        $reference = $this->publishedPlacement($this->page());
        $block = $reference->Block();
        $root = $block->getRootElement();
        self::assertNotNull($root);
        $this->strand($block);

        $this->runTask();

        self::assertSame(1, $this->rowCount(self::ELEMENT_TABLE, (int) $root->ID));
        self::assertSame(1, $this->rowCount(self::ELEMENT_TABLE, (int) $root->ID, '_Live'));
    }

    public function testLeavesThePlacementOfABlockThatIsOnlyUnpublished(): void
    {
        $page = $this->page();
        $block = GridTreeFactory::sharedBlock();
        $reference = GridTreeFactory::reference($page, $block);
        $page->publishRecursive();
        $before = $this->snapshot();

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame($before, $this->snapshot(), 'a block that exists in draft still exists');
        self::assertSame(1, $this->rowCount(self::REFERENCE_TABLE, (int) $reference->ID, '_Live'));
    }

    public function testDryRunListsThePagesWithoutWriting(): void
    {
        $page = $this->page();
        $reference = $this->publishedPlacement($page);
        $this->strand($reference->Block());
        $before = $this->snapshot();

        $result = $this->runTask(dryRun: true);

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame($before, $this->snapshot(), 'a dry run changes nothing');
        self::assertSame(sprintf(
            "Preview only: nothing is changed.\nLeftover placements of deleted shared blocks:\n"
            . "  Integration Test Page (page %d) draft and published\n"
            . "1 placement on 1 page would be removed. Their pages show visitors the same as before. Ask an editor to check them.\n",
            $page->ID,
        ), $result['output']);
    }

    public function testIsIdempotent(): void
    {
        $reference = $this->publishedPlacement($this->page());
        $this->strand($reference->Block());

        $this->runTask();
        $afterFirst = $this->snapshot();
        $second = $this->runTask();

        self::assertSame($afterFirst, $this->snapshot());
        self::assertSame(Command::SUCCESS, $second['exitCode']);
        self::assertSame("Nothing to repair: no placement of a deleted shared block is left.\n", $second['output']);
    }

    public function testFailsOnAPlacementWhoseElementRowIsMissing(): void
    {
        // Without its GridElement row a placement has no parent and no
        // locale, so nothing tells which page it belonged to.
        $page = $this->page();
        $block = GridTreeFactory::sharedBlock();
        $broken = GridTreeFactory::reference($page, $block);
        $stranded = GridTreeFactory::reference($this->page('test_page_2'), $block);
        DB::prepared_query(sprintf('DELETE FROM "%s" WHERE "ID" = ?', self::ELEMENT_TABLE), [$broken->ID]);
        $this->strand($block);

        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertSame(1, $this->rowCount(self::REFERENCE_TABLE, (int) $broken->ID), 'left for a person to decide');
        self::assertSame(0, $this->storedRows((int) $stranded->ID), 'the rest is still repaired');
        self::assertStringContainsString(sprintf(
            "Left for manual review:\n  - %s #%d (draft): its element row is missing, so its page cannot be told\n",
            SharedBlockReference::class,
            $broken->ID,
        ), $result['output']);
    }

    public function testLeavesAPlacementForManualReviewOnEveryStage(): void
    {
        // Its live rows are intact, so the live pass alone could classify it;
        // removing it there would contradict the line that leaves it to a person.
        $block = GridTreeFactory::sharedBlock();
        $reference = GridTreeFactory::reference($this->page(), $block);
        $reference->publishSingle();
        DB::prepared_query(sprintf('DELETE FROM "%s" WHERE "ID" = ?', self::ELEMENT_TABLE), [$reference->ID]);
        $this->strand($block);

        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertSame(1, $this->rowCount(self::REFERENCE_TABLE, (int) $reference->ID), 'draft row kept');
        self::assertSame(1, $this->rowCount(self::REFERENCE_TABLE, (int) $reference->ID, '_Live'), 'live row kept');
        self::assertStringNotContainsString('removed', $result['output']);
    }
}
