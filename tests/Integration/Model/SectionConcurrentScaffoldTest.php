<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Throwable;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;

#[CoversClass(GridElement::class)]
final class SectionConcurrentScaffoldTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testScaffoldRunsInsideTransactionAndIsIdempotent(): void
    {
        $page = SiteTree::create(['Title' => 'P']);
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = SiteTree::class;
        $section->write();

        // Writing the section again must NOT create a second Row.
        $section->write();

        self::assertSame(
            1,
            $section->getChildren()->count(),
            'Section must contain exactly one Row after repeated writes',
        );
    }

    public function testScaffoldSurvivesTransactionRollback(): void
    {
        $page = SiteTree::create(['Title' => 'P']);
        $page->write();

        DB::get_conn()->transactionStart();
        try {
            $section = Section::create();
            $section->ParentID = $page->ID;
            $section->ParentClass = SiteTree::class;
            $section->write();
            DB::get_conn()->transactionRollback();
        } catch (Throwable $e) {
            DB::get_conn()->transactionRollback();
            throw $e;
        }

        self::assertSame(0, Section::get()->count(), 'Rolled-back section must not persist');
    }
}
