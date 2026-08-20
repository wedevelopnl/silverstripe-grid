<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridAwareDeleteLocalisationPolicy;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Behaviour owner for GridAwareDeleteLocalisationPolicy, instantiated directly:
 *
 *   1. For pages with GridPageExtension, cascade-delete Sections in the active locale.
 *   2. Only the current locale's Sections are deleted; other locales stay intact.
 *
 * The DI wiring that swaps this policy in for Fluent's stock
 * DeleteLocalisationPolicy is pinned separately by FluentClearLocaleTest.
 */
#[CoversClass(GridAwareDeleteLocalisationPolicy::class)]
final class GridAwareDeleteLocalisationPolicyTest extends FluentGridTestCase
{

    /**
     * Deleting the Dutch localisation of a grid-bearing page must remove every
     * Section/Row/Column/ContentElement belonging to the Dutch locale, while
     * leaving the English tree intact.
     */
    public function testDeleteRemovesCurrentLocaleGridHierarchy(): void
    {
        $page = $this->createPage();

        // English tree
        $enSection = GridTreeFactory::section($page, title: 'EN Section');
        $enRow = GridTreeFactory::row($enSection);
        $enCol = GridTreeFactory::column($enRow);
        GridTreeFactory::contentElement($enCol, title: 'EN Content');

        // Dutch tree (on the same page, localised)
        FluentState::singleton()->setLocale('nl_NL');
        $page->writeToStage(Versioned::DRAFT);

        $nlSection = GridTreeFactory::section($page, title: 'NL Section');
        $nlRow = GridTreeFactory::row($nlSection);
        $nlCol = GridTreeFactory::column($nlRow);
        GridTreeFactory::contentElement($nlCol, title: 'NL Content');

        // Act: delete Dutch locale via the policy directly.
        // FluentIsolatedExtension::augmentSQL scopes queries to the active locale,
        // so Sections() only returns the NL hierarchy.
        $policy = GridAwareDeleteLocalisationPolicy::create();
        $policy->delete($page);

        // Assert: NL tree is gone
        self::assertCount(0, Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]), 'Dutch Sections should be deleted');
        self::assertCount(0, Row::get(), 'Dutch Rows should be deleted');
        self::assertCount(0, Column::get(), 'Dutch Columns should be deleted');
        self::assertCount(0, ContentElement::get(), 'Dutch ContentElements should be deleted');

        // Assert: EN tree still intact
        FluentState::singleton()->setLocale('en_US');

        self::assertCount(1, Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]), 'English Section should remain');
        self::assertCount(1, Row::get(), 'English Row should remain');
        self::assertCount(1, Column::get(), 'English Column should remain');
        self::assertCount(1, ContentElement::get(), 'English ContentElement should remain');
    }
}
