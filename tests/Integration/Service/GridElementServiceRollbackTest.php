<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridElementService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\RejectCopyTitleExtension;

/**
 * Pins the transaction wrapping around {@see GridElementService::duplicateElementTo()}.
 *
 * A {@see RejectCopyTitleExtension} fails validation for the generated "… copy"
 * clone *after* C1/C2 validation has passed, forcing the deep-duplicate write
 * down its failure path. Because the duplicate + re-parent + write are wrapped
 * in a single transaction, the rollback must leave no orphaned clone subtree.
 */
#[CoversClass(GridElementService::class)]
final class GridElementServiceRollbackTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [RejectCopyTitleExtension::class],
    ];

    private GridElementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = Injector::inst()->get(GridElementService::class);
    }

    public function testDuplicateElementToRollsBackEntireSubtreeWhenFinalWriteFails(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');

        // Build a Section -> Row subtree on page 1. None of these titles end in
        // "copy", so they persist fine; only the generated clone title will fail.
        $section = GridTreeFactory::section($page1, title: 'Original');
        GridTreeFactory::row($section, title: 'Original Row');

        $countBefore = GridElement::get()->count();

        $result = $this->service->duplicateElementTo(
            $section,
            $page2,
            (int) $page2->ID,
            'main',
        );

        self::assertTrue(
            $result->isErr(),
            'the failing clone write must surface as an err Result',
        );

        $countAfter = GridElement::get()->count();
        self::assertSame(
            $countBefore,
            $countAfter,
            'a failed deep-duplicate write must roll back atomically — no orphaned clone or clone children may remain',
        );

        // No element with a generated copy title may have been persisted.
        self::assertSame(
            0,
            GridElement::get()->filter(['Title' => 'Original copy'])->count(),
            'the rejected clone must not exist in the database after rollback',
        );
    }
}
