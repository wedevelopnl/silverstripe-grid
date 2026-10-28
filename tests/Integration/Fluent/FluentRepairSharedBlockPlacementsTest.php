<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use TractorCow\Fluent\Service\CopyToLocaleService;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Task\OneTime\Beta6\RepairSharedBlockPlacementsTask;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\StrandsSharedBlockPlacements;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * The earlier delete stranded the placements of every locale but the active
 * one. The repair reads raw rows, so it reaches every locale and names each in
 * the list of pages to check.
 */
#[CoversClass(RepairSharedBlockPlacementsTask::class)]
final class FluentRepairSharedBlockPlacementsTest extends FluentGridTestCase
{
    use StrandsSharedBlockPlacements;

    public function testRemovesStrandedPlacementsInEveryLocaleAndNamesTheLocale(): void
    {
        $page = $this->createPage('Contact');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '', title: 'Shared section');
        $enReference = GridTreeFactory::reference($page, $block, zone: 'main');
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        $nlReference = FluentState::singleton()->withState(static function (FluentState $state) use ($page): ?SharedBlockReference {
            $state->setLocale('nl_NL');
            $nlPage = Page::get()->byID($page->ID);
            self::assertNotNull($nlPage);
            $nlPage->Title = 'Contact NL';
            $nlPage->writeToStage('Stage');

            return SharedBlockReference::get()->filter(['ParentID' => $page->ID])->first();
        });
        self::assertNotNull($nlReference, 'precondition: the page copy carried the placement');

        $this->strand($block);

        $result = TaskRunner::run(RepairSharedBlockPlacementsTask::singleton());

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame(0, $this->storedRows((int) $enReference->ID));
        self::assertSame(0, $this->storedRows((int) $nlReference->ID));
        self::assertStringContainsString(sprintf(
            "  en_US Contact (page %1\$d) draft\n  nl_NL Contact NL (page %1\$d) draft\n"
            . '2 placements on 2 pages removed.',
            $page->ID,
        ), $result['output']);
    }
}
