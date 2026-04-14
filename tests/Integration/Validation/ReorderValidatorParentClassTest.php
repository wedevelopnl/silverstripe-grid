<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Validation\ReorderValidator;

#[CoversClass(ReorderValidator::class)]
final class ReorderValidatorParentClassTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);
    }

    /**
     * The same-parent shortcut must compare ParentClass in addition to ParentID.
     *
     * Page IDs and element IDs share a single numeric namespace. Without a class
     * comparison, a move whose element.ParentID happens to equal targetParent.ID
     * (where targetParent is a different class) would skip hierarchy validation.
     */
    public function testSameParentShortcutChecksParentClassNotJustParentId(): void
    {
        $page = SiteTree::create();
        $page->Title = 'Page';
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = SiteTree::class;
        $section->write();

        // Row lives under the Section. row->ParentID === section->ID.
        $row = Row::create();
        $row->ParentID = $section->ID;
        $row->ParentClass = Section::class;
        $row->write();

        // Fabricate a SiteTree whose numeric ID collides with section->ID but
        // whose class is SiteTree (not Section). The validator only reads ID
        // and class from the target parent, so the record need not be written.
        $collidingPage = SiteTree::create();
        $collidingPage->Title = 'Colliding';
        $collidingPage->ID = $section->ID;

        $validator = new ReorderValidator();
        $result = $validator->validate($row, $collidingPage);

        // Row cannot live directly under a SiteTree page (can_be_root: false).
        // If the shortcut bypasses class comparison, this test will incorrectly
        // receive Result::ok().
        self::assertTrue(
            $result->isErr(),
            'Row must not be placeable directly under a SiteTree page',
        );
    }
}
