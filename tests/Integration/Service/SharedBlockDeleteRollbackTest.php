<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Service\SharedBlockService;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\RejectMarkedPageExtension;
use WeDevelop\Grid\Value\SharedBlockDeleteMode;
use WeDevelop\Grid\Value\ValidationError;

/**
 * Pins that a shared block delete and the consuming pages' new draft versions
 * commit together: a page that cannot be written rolls the whole delete back.
 */
#[CoversClass(SharedBlockService::class)]
final class SharedBlockDeleteRollbackTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        SiteTree::class => [RejectMarkedPageExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableAutoScaffolding();
        Versioned::set_stage(Versioned::DRAFT);
        $this->logInWithPermission('ADMIN');
    }

    public function testAPageThatCannotBeWrittenRollsTheWholeDeleteBack(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '', title: 'Shared section');
        $reference = GridTreeFactory::reference($page, $block);

        DB::prepared_query(
            sprintf('UPDATE "%s" SET "Title" = ? WHERE "ID" = ?', DataObject::getSchema()->baseDataTable(SiteTree::class)),
            [RejectMarkedPageExtension::FAIL_MARKER, $page->ID],
        );

        $result = Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Remove);

        self::assertSame(
            [RejectMarkedPageExtension::MESSAGE],
            array_map(static fn (ValidationError $error): string => $error->message, $result->errors()),
            'the failure must be the page write, which runs after the block is archived',
        );
        self::assertNotNull(SharedBlock::get()->byID($block->ID), 'the block survives');
        self::assertNotNull(SharedBlockReference::get()->byID($reference->ID), 'and so does its placement');
    }
}
