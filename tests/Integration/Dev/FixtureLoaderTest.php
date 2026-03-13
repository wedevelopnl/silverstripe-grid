<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Dev\FixtureLoader;
use WeDevelop\Grid\Dev\FixtureResult;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;

#[CoversClass(FixtureLoader::class)]
#[CoversClass(FixtureResult::class)]
final class FixtureLoaderTest extends SapphireTest
{
    protected $usesDatabase = true;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FixtureLoader::config()->set('fixtures', [
            'element-tree' => 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/ElementTree.yml',
            'empty-page' => 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/EmptyPage.yml',
            'complex-page' => [
                'path' => 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/ComplexPage.yml',
                'post_actions' => [
                    [
                        'action' => 'publish_recursive',
                        'class' => Page::class,
                        'identifier' => 'e2e_page',
                    ],
                    [
                        'action' => 'unpublish',
                        'class' => ContentElement::class,
                        'identifier' => 'draft_leaf',
                    ],
                    [
                        'action' => 'modify',
                        'class' => ContentElement::class,
                        'identifier' => 'modified_leaf',
                        'fields' => ['Title' => 'Modified Text Block (draft)'],
                    ],
                ],
            ],
        ]);
    }

    public function testLoadCreatesPageWithElementHierarchy(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('element-tree');

        $this->assertInstanceOf(FixtureResult::class, $result);
        $this->assertSame('element-tree', $result->fixtureName);
        $this->assertGreaterThan(0, $result->pageId);
        $this->assertNotEmpty($result->pageUrl);

        // Verify page exists in draft
        Versioned::withVersionedMode(function () use ($result): void {
            Versioned::set_stage(Versioned::DRAFT);

            $page = SiteTree::get()->byID($result->pageId);
            $this->assertNotNull($page, 'Page should exist in draft');
            $this->assertSame('e2e-grid-test', $page->URLSegment);
        });

        // Verify fixture map contains expected classes
        $this->assertArrayHasKey(Page::class, $result->fixtureMap);
        $this->assertArrayHasKey(Section::class, $result->fixtureMap);
        $this->assertArrayHasKey(Row::class, $result->fixtureMap);
        $this->assertArrayHasKey(Column::class, $result->fixtureMap);
        $this->assertArrayHasKey(ContentElement::class, $result->fixtureMap);

        // Verify hierarchy: section → row → columns → leaves
        Versioned::withVersionedMode(function () use ($result): void {
            Versioned::set_stage(Versioned::DRAFT);

            $sectionId = $result->fixtureMap[Section::class]['section1'];
            $section = Section::get()->byID($sectionId);
            $this->assertNotNull($section, 'Section should exist');

            $rowId = $result->fixtureMap[Row::class]['row1'];
            $row = Row::get()->byID($rowId);
            $this->assertNotNull($row, 'Row should exist');

            // Row should be parented to section
            $this->assertSame(
                (int) $section->ID,
                (int) $row->ParentID,
                'Row should be parented to section',
            );

            $col1Id = $result->fixtureMap[Column::class]['col1'];
            $col1 = Column::get()->byID($col1Id);
            $this->assertNotNull($col1, 'Column 1 should exist');

            // Column should be parented to row
            $this->assertSame(
                (int) $row->ID,
                (int) $col1->ParentID,
                'Column should be parented to row',
            );

            // Leaves should be parented to column
            $leaf1Id = $result->fixtureMap[ContentElement::class]['leaf1'];
            $leaf1 = GridElement::get()->byID($leaf1Id);
            $this->assertNotNull($leaf1, 'Leaf 1 should exist');
            $this->assertSame(
                (int) $col1->ID,
                (int) $leaf1->ParentID,
                'Leaf should be parented to column',
            );
        });
    }

    public function testLoadSuppressesAutoScaffolding(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('element-tree');

        Versioned::withVersionedMode(function () use ($result): void {
            Versioned::set_stage(Versioned::DRAFT);

            $sectionId = $result->fixtureMap[Section::class]['section1'];
            $section = Section::get()->byID($sectionId);

            // Fixture defines exactly 1 row under section — no auto-scaffolded duplicates
            $this->assertCount(1, $section->getChildren());

            $rowId = $result->fixtureMap[Row::class]['row1'];
            $row = Row::get()->byID($rowId);

            // Fixture defines exactly 2 columns under row — no auto-scaffolded duplicates
            $this->assertCount(2, $row->getChildren());
        });
    }

    public function testLoadAppliesPostActions(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('complex-page');

        $modifiedLeafId = $result->fixtureMap[ContentElement::class]['modified_leaf'];

        // The modify post-action should have changed the draft title,
        // proving post-actions ran during load()
        Versioned::withVersionedMode(function () use ($modifiedLeafId): void {
            Versioned::set_stage(Versioned::DRAFT);
            $draft = GridElement::get()->byID($modifiedLeafId);
            $this->assertNotNull($draft);
            $this->assertSame(
                'Modified Text Block (draft)',
                $draft->Title,
                'Post-action should have modified the draft title',
            );
        });
    }

    public function testResetRemovesAllE2ePages(): void
    {
        $loader = FixtureLoader::create();
        $loader->load('element-tree');

        // Verify page exists before reset
        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);
            $pages = SiteTree::get()->filter('URLSegment:StartsWith', 'e2e-');
            self::assertGreaterThan(0, $pages->count(), 'E2E pages should exist before reset');
        });

        $loader->reset();

        // Verify all E2E pages are gone
        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);
            $pages = SiteTree::get()->filter('URLSegment:StartsWith', 'e2e-');
            self::assertCount(0, $pages, 'All E2E pages should be removed after reset');
        });
    }

    public function testLoadIsIdempotent(): void
    {
        $loader = FixtureLoader::create();

        $loader->load('element-tree');
        $result2 = $loader->load('element-tree');

        // Second load should produce a valid result without duplicates
        $this->assertSame('element-tree', $result2->fixtureName);

        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);

            $pages = SiteTree::get()->filter('URLSegment:StartsWith', 'e2e-');
            self::assertCount(1, $pages, 'Only one E2E page should exist after loading twice');
        });
    }

    public function testResetActuallyDeletesFixtureData(): void
    {
        $loader = FixtureLoader::create();
        $loader->load('element-tree');

        // Verify data exists
        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);
            self::assertGreaterThan(
                0,
                SiteTree::get()->filter('URLSegment:StartsWith', 'e2e-')->count(),
            );
        });

        $loader->reset();

        // Verify pages removed after reset
        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);
            self::assertCount(
                0,
                SiteTree::get()->filter('URLSegment:StartsWith', 'e2e-'),
                'reset() should remove all E2E pages',
            );
        });
    }

    public function testLoadThrowsForFixtureWithNullPath(): void
    {
        FixtureLoader::config()->merge('fixtures', [
            'null-path' => ['path' => null],
        ]);

        $loader = FixtureLoader::create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no path configured');

        $loader->load('null-path');
    }

    public function testStringConfigFixtureHasNoPostActions(): void
    {
        // 'empty-page' is configured as a plain string path (no array config)
        $loader = FixtureLoader::create();
        $result = $loader->load('empty-page');

        // If resolvePostActions broke (|| → &&), it would try to access
        // string config as array and fail. Success means no post-actions applied.
        $this->assertSame('empty-page', $result->fixtureName);
    }

    public function testLoadThrowsForUnknownFixture(): void
    {
        $loader = FixtureLoader::create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown fixture "nonexistent"');

        $loader->load('nonexistent');
    }

    public function testGetAvailableFixtures(): void
    {
        $loader = FixtureLoader::create();
        $available = $loader->getAvailableFixtures();

        $this->assertContains('element-tree', $available);
        $this->assertContains('empty-page', $available);
        $this->assertContains('complex-page', $available);
    }

    public function testFixtureResultJsonSerialization(): void
    {
        $result = new FixtureResult(
            fixtureName: 'test',
            pageId: 42,
            pageUrl: '/test-page/',
            fixtureMap: [
                Page::class => ['page1' => 42],
            ],
        );

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(42, $decoded['pageId']);
        $this->assertSame('/test-page/', $decoded['pageUrl']);
        $this->assertArrayHasKey(Page::class, $decoded['fixtureMap']);
    }
}
