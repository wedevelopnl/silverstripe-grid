<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Psr\Log\NullLogger;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestCustomElement;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestPage;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestCustomElementMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestCustomElementReaderExtension;

/**
 * End-to-end acceptance tests for the SS5 → SS6 grid migration pipeline.
 *
 * Each test seeds a realistic old-module page, runs the full migration, and
 * asserts the ENTIRE resulting hierarchy — every Section, Row, Column, content
 * element, grid setting, and media field.
 */
#[CoversNothing]
final class MigrationAcceptanceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        TestCustomElement::class,
        TestPage::class,
    ];

    protected $usesTransactions = false;

    private const string DEFAULT_VIEWPORT = 'MD';

    private const string ZONE = 'main';

    private const array VIEWPORT_KEY_MAP = [
        'XS' => 'xs',
        'SM' => 'sm',
        'MD' => 'md',
        'LG' => 'lg',
        'XL' => 'xl',
    ];

    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->truncateTables();
        $this->cleanGridTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->dropTables();
        parent::tearDown();
    }

    // ─── Scenario 1: Simple text page ─────────────────────────────

    public function testSimpleTextPage(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Row 1: full-width text element
        $this->seeder->seedElement(100, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(100);
        $this->seeder->seedElement(101, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12, 'Title' => 'Introduction', 'ShowTitle' => 1, 'TitleTag' => 'h2',
        ]);
        $this->seeder->seedContentMedia(101, ['HTML' => '<p>Welcome to our site.</p>']);

        // Row 2: two-column layout
        $this->seeder->seedElement(200, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(200);
        $this->seeder->seedElement(201, $areaId, self::CONTENT_CLASS, 4, [
            'SizeMD' => 8, 'Title' => 'Main Content',
        ]);
        $this->seeder->seedContentMedia(201, ['HTML' => '<p>Article body.</p>']);
        $this->seeder->seedElement(202, $areaId, self::CONTENT_CLASS, 5, [
            'SizeMD' => 4, 'Title' => 'Sidebar',
        ]);
        $this->seeder->seedContentMedia(202, ['HTML' => '<p>Related links.</p>']);

        $this->runRowPerSection($pageId);

        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Introduction',
                                    'showTitle' => true,
                                    'titleTag' => 'h2',
                                    'html' => '<p>Welcome to our site.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Main Content',
                                    'html' => '<p>Article body.</p>',
                                ],
                            ],
                            [
                                'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Sidebar',
                                    'html' => '<p>Related links.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    // ─── Scenario 2: Marketing landing page ───────────────────────

    public function testMarketingLandingPage(): void
    {
        $pageId = $this->getPageId();
        $areaId = 500;
        $this->seeder->seedPage($pageId, $areaId);

        // Row 1: Hero section with media
        $this->seeder->seedElement(300, $areaId, self::ROW_CLASS, 1, [
            'Title' => 'Hero Row', 'ExtraClass' => 'bg-primary',
        ]);
        $this->seeder->seedRow(300, customSectionClass: 'hero');
        $this->seeder->seedElement(301, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12, 'Title' => 'Hero Banner', 'ShowTitle' => 0,
        ]);
        $this->seeder->seedContentMedia(301, [
            'HTML' => '<h1>Big Hero Headline</h1>',
            'MediaType' => 'image',
            'MediaImageID' => 42,
            'MediaRatio' => '16x9',
            'MediaPosition' => 'order-1',
            'ContentColumns' => '6',
            'ContentVerticalAlign' => 'align-items-center',
            'ExtraColumnGap' => 5,
        ]);

        // Row 2: Two-column with viewport overrides
        $this->seeder->seedElement(400, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(400);
        $this->seeder->seedElement(401, $areaId, self::CONTENT_CLASS, 4, [
            'SizeMD' => 8, 'SizeXL' => 6, 'OffsetXL' => 3, 'Title' => 'Feature Text',
        ]);
        $this->seeder->seedContentMedia(401, ['HTML' => '<p>Our features explained.</p>']);
        $this->seeder->seedElement(402, $areaId, self::CONTENT_CLASS, 5, [
            'SizeMD' => 4, 'SizeXL' => 6, 'VisibilityXS' => 'hidden',
            'Title' => 'Feature Image', 'ShowTitle' => 1, 'TitleTag' => 'h3', 'TitleClass' => 'text-lg',
        ]);
        $this->seeder->seedContentMedia(402, ['HTML' => '<p>Visual showcase.</p>']);

        // Row 3: CTA with video
        $this->seeder->seedElement(500, $areaId, self::ROW_CLASS, 6, ['ExtraClass' => 'bg-dark']);
        $this->seeder->seedRow(500, customSectionClass: 'cta-section');
        $this->seeder->seedElement(501, $areaId, self::CONTENT_CLASS, 7, [
            'SizeMD' => 12, 'Title' => 'Watch Our Video',
        ]);
        $this->seeder->seedContentMedia(501, [
            'HTML' => '<p>See us in action.</p>',
            'MediaType' => 'video',
            'MediaVideoFullURL' => 'https://youtube.com/watch?v=abc123',
            'ExtraColumnGap' => 7,
            'MediaPosition' => 'order-1 order-md-2',
        ]);

        $this->runRowPerSection($pageId);

        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            // Section 1: Hero
            [
                'extraClass' => 'hero',
                'rows' => [
                    [
                        'title' => 'Hero Row',
                        'extraClass' => 'bg-primary',
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Hero Banner',
                                    'showTitle' => false,
                                    'html' => '<h1>Big Hero Headline</h1>',
                                    'mediaType' => 'image',
                                    'mediaImageID' => 42,
                                    'mediaRatio' => '16x9',
                                    'mediaPosition' => 'first',
                                    'contentColumns' => 6,
                                    'verticalAlignment' => 'center',
                                    'gapSize' => 2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Section 2: Features with viewport overrides
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [
                                    'xl' => ['width' => 6, 'offset' => 3, 'visible' => true],
                                ],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Feature Text',
                                    'html' => '<p>Our features explained.</p>',
                                ],
                            ],
                            [
                                'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [
                                    'xs' => ['width' => 4, 'offset' => 0, 'visible' => false],
                                    'xl' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                ],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Feature Image',
                                    'showTitle' => true,
                                    'titleTag' => 'h3',
                                    'titleClass' => 'text-lg',
                                    'html' => '<p>Visual showcase.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Section 3: CTA with video
            [
                'extraClass' => 'cta-section',
                'rows' => [
                    [
                        'extraClass' => 'bg-dark',
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Watch Our Video',
                                    'html' => '<p>See us in action.</p>',
                                    'mediaType' => 'video',
                                    'videoURL' => 'https://youtube.com/watch?v=abc123',
                                    'mediaPosition' => 'last-on-desktop',
                                    'gapSize' => 3,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    // ─── Scenario 3: Orphan elements between rows ─────────────────

    public function testPageWithOrphanElements(): void
    {
        $pageId = $this->getPageId();
        $areaId = 200;
        $this->seeder->seedPage($pageId, $areaId);

        // Orphan before any row
        $this->seeder->seedElement(600, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6, 'Title' => 'Orphan Before',
        ]);
        $this->seeder->seedContentMedia(600, ['HTML' => '<p>Before any row.</p>']);

        // Row 1 + element in its group
        $this->seeder->seedElement(700, $areaId, self::ROW_CLASS, 2);
        $this->seeder->seedRow(700);
        $this->seeder->seedElement(701, $areaId, self::CONTENT_CLASS, 3, [
            'SizeMD' => 12, 'Title' => 'In Row 1',
        ]);
        $this->seeder->seedContentMedia(701, ['HTML' => '<p>Row 1 content.</p>']);
        // Element after Row 1 but before Row 2 — belongs to Row 1's group
        $this->seeder->seedElement(702, $areaId, self::CONTENT_CLASS, 4, [
            'SizeMD' => 4, 'Title' => 'Between Rows',
        ]);
        $this->seeder->seedContentMedia(702, ['HTML' => '<p>Between rows.</p>']);

        // Row 2 + element + trailing element
        $this->seeder->seedElement(800, $areaId, self::ROW_CLASS, 5);
        $this->seeder->seedRow(800);
        $this->seeder->seedElement(801, $areaId, self::CONTENT_CLASS, 6, [
            'SizeMD' => 12, 'Title' => 'In Row 2',
        ]);
        $this->seeder->seedContentMedia(801, ['HTML' => '<p>Row 2 content.</p>']);
        $this->seeder->seedElement(802, $areaId, self::CONTENT_CLASS, 7, [
            'SizeMD' => 8, 'Title' => 'Trailing',
        ]);
        $this->seeder->seedContentMedia(802, ['HTML' => '<p>After last row.</p>']);

        $this->runRowPerSection($pageId);

        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            // Section 1: implicit group (orphan before any row)
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Orphan Before',
                                    'html' => '<p>Before any row.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Section 2: Row 1's group (In Row 1 + Between Rows)
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'In Row 1',
                                    'html' => '<p>Row 1 content.</p>',
                                ],
                            ],
                            [
                                'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Between Rows',
                                    'html' => '<p>Between rows.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Section 3: Row 2's group (In Row 2 + Trailing)
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'In Row 2',
                                    'html' => '<p>Row 2 content.</p>',
                                ],
                            ],
                            [
                                'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Trailing',
                                    'html' => '<p>After last row.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    // ─── Scenario 4: Draft/Live divergence ────────────────────────

    public function testDraftLiveDivergence(): void
    {
        $pageId = $this->getPageId();
        $areaId = 900;
        $this->seeder->seedPage($pageId, $areaId);

        // DRAFT: Row 1 + element
        $this->seeder->seedElement(1000, $areaId, self::ROW_CLASS, 1, [], 'draft');
        $this->seeder->seedRow(1000, customSectionClass: '', stage: 'draft');
        $this->seeder->seedElement(1001, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 8, 'Title' => 'Draft Version',
        ], 'draft');
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Updated draft content</p>'], 'draft');

        // DRAFT: Row 2 (only on draft)
        $this->seeder->seedElement(1100, $areaId, self::ROW_CLASS, 3, [], 'draft');
        $this->seeder->seedRow(1100, stage: 'draft');
        $this->seeder->seedElement(1101, $areaId, self::CONTENT_CLASS, 4, [
            'SizeMD' => 12, 'Title' => 'New Section',
        ], 'draft');
        $this->seeder->seedContentMedia(1101, ['HTML' => '<p>Not yet published</p>'], 'draft');

        // LIVE: Only Row 1 + element (with different content)
        $this->seeder->seedElement(1000, $areaId, self::ROW_CLASS, 1, [], 'live');
        $this->seeder->seedRow(1000, customSectionClass: '', stage: 'live');
        $this->seeder->seedElement(1001, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6, 'Title' => 'Live Version',
        ], 'live');
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Published content</p>'], 'live');

        $this->runRowPerSection($pageId);

        // Assert draft: 2 sections
        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Draft Version',
                                    'html' => '<p>Updated draft content</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'New Section',
                                    'html' => '<p>Not yet published</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Assert live: 1 section. Grid settings (Column width) are published
        // from draft via writeToStage(LIVE) — overwriteLiveContent only corrects
        // element content fields, not Column grid settings.
        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::LIVE, [
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Live Version',
                                    'html' => '<p>Published content</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Verify draft Section 1 and live Section 1 share the same ID
        Versioned::set_stage(Versioned::DRAFT);
        $draftSection = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => SiteTree::class,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC')->first();

        Versioned::set_stage(Versioned::LIVE);
        $liveSection = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => SiteTree::class,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC')->first();

        self::assertInstanceOf(Section::class, $draftSection);
        self::assertInstanceOf(Section::class, $liveSection);
        self::assertSame((int) $draftSection->ID, (int) $liveSection->ID, 'Draft and live Section 1 share the same ID');
    }

    // ─── Scenario 5: All defaults page ────────────────────────────

    public function testAllDefaultsPage(): void
    {
        $pageId = $this->getPageId();
        $areaId = 300;
        $this->seeder->seedPage($pageId, $areaId);

        // Row with a single element using only SizeMD, everything else at defaults
        $this->seeder->seedElement(1200, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(1200);
        $this->seeder->seedElement(1201, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12,
        ]);
        $this->seeder->seedContentMedia(1201, []);

        $this->runRowPerSection($pageId);

        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    // ensureDefaultTitle() auto-generates a title when empty
                                    'title' => 'Content element 1',
                                    'showTitle' => false,
                                    'mediaRatio' => 'auto',
                                    'mediaPosition' => 'first',
                                    'contentColumns' => 0,
                                    'verticalAlignment' => 'top',
                                    'gapSize' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    // ─── Scenario 6: All rows in single section ──────────────────

    public function testMarketingPageAllRowsInSingleSection(): void
    {
        $pageId = $this->getPageId();
        $areaId = 500;
        $this->seeder->seedPage($pageId, $areaId);

        // Same seed as scenario 2
        // Row 1: Hero section
        $this->seeder->seedElement(300, $areaId, self::ROW_CLASS, 1, [
            'Title' => 'Hero Row', 'ExtraClass' => 'bg-primary',
        ]);
        $this->seeder->seedRow(300, customSectionClass: 'hero');
        $this->seeder->seedElement(301, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12, 'Title' => 'Hero Banner', 'ShowTitle' => 0,
        ]);
        $this->seeder->seedContentMedia(301, [
            'HTML' => '<h1>Big Hero Headline</h1>',
            'MediaType' => 'image',
            'MediaImageID' => 42,
            'MediaRatio' => '16x9',
            'MediaPosition' => 'order-1',
            'ContentColumns' => '6',
            'ContentVerticalAlign' => 'align-items-center',
            'ExtraColumnGap' => 5,
        ]);

        // Row 2: Two-column with viewport overrides
        $this->seeder->seedElement(400, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(400);
        $this->seeder->seedElement(401, $areaId, self::CONTENT_CLASS, 4, [
            'SizeMD' => 8, 'SizeXL' => 6, 'OffsetXL' => 3, 'Title' => 'Feature Text',
        ]);
        $this->seeder->seedContentMedia(401, ['HTML' => '<p>Our features explained.</p>']);
        $this->seeder->seedElement(402, $areaId, self::CONTENT_CLASS, 5, [
            'SizeMD' => 4, 'SizeXL' => 6, 'VisibilityXS' => 'hidden',
            'Title' => 'Feature Image', 'ShowTitle' => 1, 'TitleTag' => 'h3', 'TitleClass' => 'text-lg',
        ]);
        $this->seeder->seedContentMedia(402, ['HTML' => '<p>Visual showcase.</p>']);

        // Row 3: CTA with video
        $this->seeder->seedElement(500, $areaId, self::ROW_CLASS, 6, ['ExtraClass' => 'bg-dark']);
        $this->seeder->seedRow(500, customSectionClass: 'cta-section');
        $this->seeder->seedElement(501, $areaId, self::CONTENT_CLASS, 7, [
            'SizeMD' => 12, 'Title' => 'Watch Our Video',
        ]);
        $this->seeder->seedContentMedia(501, [
            'HTML' => '<p>See us in action.</p>',
            'MediaType' => 'video',
            'MediaVideoFullURL' => 'https://youtube.com/watch?v=abc123',
            'ExtraColumnGap' => 7,
            'MediaPosition' => 'order-1 order-md-2',
        ]);

        $this->runAllRowsInSection($pageId);

        // AllRowsInSection: 1 Section, 3 Rows
        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            [
                'rows' => [
                    // Row 1: Hero
                    [
                        'title' => 'Hero Row',
                        'extraClass' => 'bg-primary',
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Hero Banner',
                                    'showTitle' => false,
                                    'html' => '<h1>Big Hero Headline</h1>',
                                    'mediaType' => 'image',
                                    'mediaImageID' => 42,
                                    'mediaRatio' => '16x9',
                                    'mediaPosition' => 'first',
                                    'contentColumns' => 6,
                                    'verticalAlignment' => 'center',
                                    'gapSize' => 2,
                                ],
                            ],
                        ],
                    ],
                    // Row 2: Features
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [
                                    'xl' => ['width' => 6, 'offset' => 3, 'visible' => true],
                                ],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Feature Text',
                                    'html' => '<p>Our features explained.</p>',
                                ],
                            ],
                            [
                                'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [
                                    'xs' => ['width' => 4, 'offset' => 0, 'visible' => false],
                                    'xl' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                ],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Feature Image',
                                    'showTitle' => true,
                                    'titleTag' => 'h3',
                                    'titleClass' => 'text-lg',
                                    'html' => '<p>Visual showcase.</p>',
                                ],
                            ],
                        ],
                    ],
                    // Row 3: CTA
                    [
                        'extraClass' => 'bg-dark',
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Watch Our Video',
                                    'html' => '<p>See us in action.</p>',
                                    'mediaType' => 'video',
                                    'videoURL' => 'https://youtube.com/watch?v=abc123',
                                    'mediaPosition' => 'last-on-desktop',
                                    'gapSize' => 3,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    // ─── Scenario 7: Adjacent empty rows and mixed content ────────

    public function testAdjacentEmptyRowsAndMixedContent(): void
    {
        $pageId = $this->getPageId();
        $areaId = 400;
        $this->seeder->seedPage($pageId, $areaId);

        // Two empty rows (no content elements following them)
        $this->seeder->seedElement(1300, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(1300);
        $this->seeder->seedElement(1400, $areaId, self::ROW_CLASS, 2);
        $this->seeder->seedRow(1400);

        // Row 3 with 2 media-rich elements
        $this->seeder->seedElement(1500, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(1500);
        $this->seeder->seedElement(1501, $areaId, self::CONTENT_CLASS, 4, [
            'SizeMD' => 6, 'Title' => 'Gallery Left',
        ]);
        $this->seeder->seedContentMedia(1501, [
            'HTML' => '<p>Left panel.</p>',
            'MediaType' => 'image',
            'MediaImageID' => 99,
            'MediaRatio' => '1x1',
            'MediaPosition' => 'order-1',
            'ContentColumns' => '4',
            'ContentVerticalAlign' => 'align-items-end',
            'ExtraColumnGap' => 3,
        ]);
        $this->seeder->seedElement(1502, $areaId, self::CONTENT_CLASS, 5, [
            'SizeMD' => 6, 'Title' => 'Gallery Right',
        ]);
        $this->seeder->seedContentMedia(1502, [
            'HTML' => '<p>Right panel.</p>',
            'MediaType' => 'image',
            'MediaImageID' => 100,
            'MediaRatio' => '4x3',
            'MediaPosition' => 'order-2',
            'ContentVerticalAlign' => '',
            'ExtraColumnGap' => 0,
        ]);

        $this->runRowPerSection($pageId);

        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            // Section 1: empty row 1
            [
                'rows' => [
                    [
                        'columns' => [],
                    ],
                ],
            ],
            // Section 2: empty row 2
            [
                'rows' => [
                    [
                        'columns' => [],
                    ],
                ],
            ],
            // Section 3: row with 2 media-rich elements
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Gallery Left',
                                    'html' => '<p>Left panel.</p>',
                                    'mediaType' => 'image',
                                    'mediaImageID' => 99,
                                    'mediaRatio' => '1x1',
                                    'mediaPosition' => 'first',
                                    'contentColumns' => 4,
                                    'verticalAlignment' => 'bottom',
                                    'gapSize' => 1,
                                ],
                            ],
                            [
                                'gridDefault' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Gallery Right',
                                    'html' => '<p>Right panel.</p>',
                                    'mediaType' => 'image',
                                    'mediaImageID' => 100,
                                    'mediaRatio' => '4x3',
                                    'mediaPosition' => 'last',
                                    'verticalAlignment' => 'top',
                                    'gapSize' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    // ─── Scenario 8: Cross-framework migration (Bootstrap → Tailwind) ──

    /**
     * Verifies migration when changing CSS framework during the SS5→SS6 upgrade.
     *
     * Old module used Bootstrap (XS, SM, MD, LG, XL).
     * New module uses Tailwind (sm, md, lg, xl, 2xl — no xs equivalent).
     *
     * Key behaviors:
     * - XS viewport data is LOST (no mapping target) — accepted trade-off
     * - MD remains the default viewport
     * - XL maps to xl, SM maps to sm, LG maps to lg
     * - Viewport overrides use the new Tailwind keys
     */
    public function testCrossFrameworkMigrationBootstrapToTailwind(): void
    {
        $pageId = $this->getPageId();
        $areaId = 1700;
        $this->seeder->seedPage($pageId, $areaId);

        // Tailwind viewport map: no XS mapping (Tailwind has no xs breakpoint)
        $tailwindKeyMap = [
            'SM' => 'sm',
            'MD' => 'md',
            'LG' => 'lg',
            'XL' => 'xl',
        ];

        // Row with elements that have viewport-specific grid settings
        $this->seeder->seedElement(1700, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(1700);

        // Element with XS-specific visibility (will be lost in Tailwind migration)
        $this->seeder->seedElement(1701, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 8,
            'OffsetMD' => 2,
            'SizeXL' => 6,
            'OffsetXL' => 3,
            'SizeSM' => 12,
            'VisibilityXS' => 'hidden',
            'Title' => 'Responsive Element',
        ]);
        $this->seeder->seedContentMedia(1701, ['HTML' => '<p>Responsive content.</p>']);

        // Element with only default viewport (no overrides needed)
        $this->seeder->seedElement(1702, $areaId, self::CONTENT_CLASS, 3, [
            'SizeMD' => 4,
            'Title' => 'Simple Element',
        ]);
        $this->seeder->seedContentMedia(1702, ['HTML' => '<p>Simple content.</p>']);

        $this->runRowPerSection($pageId, 'MD', $tailwindKeyMap);

        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                // Default from MD: width=8, offset=2
                                'gridDefault' => ['width' => 8, 'offset' => 2, 'visible' => true],
                                'gridOverrides' => [
                                    // SM override: width=12 (differs from default 8)
                                    'sm' => ['width' => 12, 'offset' => 0, 'visible' => true],
                                    // XL override: width=6, offset=3
                                    'xl' => ['width' => 6, 'offset' => 3, 'visible' => true],
                                    // XS data (VisibilityXS=hidden) is LOST — no mapping for XS
                                    // LG not present — no old data for LG, so no override
                                ],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Responsive Element',
                                    'html' => '<p>Responsive content.</p>',
                                ],
                            ],
                            [
                                // Only MD set — no overrides
                                'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                'gridOverrides' => [],
                                'element' => [
                                    'className' => ContentElement::class,
                                    'title' => 'Simple Element',
                                    'html' => '<p>Simple content.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    // ─── Scenario 9: Custom element with project-specific fields ──

    /**
     * Verifies the full custom element migration path:
     * 1. LegacyDataReader hook reads custom fields from a legacy subclass table
     * 2. GridMigrationService hook maps old ClassName → new ClassName
     * 3. GridMigrationService hook sets custom fields from extraData
     *
     * This is the pattern every project with custom element types must implement.
     */
    public function testCustomElementWithProjectSpecificFields(): void
    {
        $pageId = $this->getPageId();
        $areaId = 1600;
        $this->seeder->seedPage($pageId, $areaId);

        $heroClassName = 'App\\Elements\\HeroBlock';

        // Create the legacy HeroBlock table (simulates old project-specific table)
        DB::query(<<<SQL
            CREATE TABLE IF NOT EXISTS "LegacyHeroBlock" (
                "ID" int NOT NULL PRIMARY KEY,
                "Subtitle" varchar(255) NOT NULL DEFAULT '',
                "ButtonText" varchar(255) NOT NULL DEFAULT ''
            )
            SQL);

        // Row with mixed element types: one standard ContentElement, one custom HeroBlock
        $this->seeder->seedElement(1600, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(1600, customSectionClass: 'mixed-section');

        // Standard ContentElement
        $this->seeder->seedElement(1601, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 8, 'Title' => 'Regular Content',
        ]);
        $this->seeder->seedContentMedia(1601, ['HTML' => '<p>Normal text block.</p>']);

        // Custom HeroBlock (stored in BaseElement with custom ClassName)
        $this->seeder->seedElement(1602, $areaId, $heroClassName, 3, [
            'SizeMD' => 4, 'Title' => 'Hero CTA', 'ShowTitle' => 1, 'TitleTag' => 'h3',
            'ExtraClass' => 'hero-cta',
        ]);
        // Seed the custom subclass table
        DB::prepared_query(
            'INSERT INTO "LegacyHeroBlock" ("ID", "Subtitle", "ButtonText") VALUES (?, ?, ?)',
            [1602, 'Discover More', 'Get Started'],
        );

        // Register both extensions to complete the custom element migration chain
        LegacyDataReader::add_extension(TestCustomElementReaderExtension::class);
        GridMigrationService::add_extension(TestCustomElementMigrationExtension::class);

        try {
            $this->runRowPerSection($pageId);

            $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
                [
                    'extraClass' => 'mixed-section',
                    'rows' => [
                        [
                            'columns' => [
                                [
                                    'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                    'gridOverrides' => [],
                                    'element' => [
                                        'className' => ContentElement::class,
                                        'title' => 'Regular Content',
                                        'html' => '<p>Normal text block.</p>',
                                    ],
                                ],
                                [
                                    'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                    'gridOverrides' => [],
                                    'element' => [
                                        'className' => TestCustomElement::class,
                                        'title' => 'Hero CTA',
                                        'showTitle' => true,
                                        'titleTag' => 'h3',
                                        'extraClass' => 'hero-cta',
                                        'subtitle' => 'Discover More',
                                        'buttonText' => 'Get Started',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        } finally {
            GridMigrationService::remove_extension(TestCustomElementMigrationExtension::class);
            LegacyDataReader::remove_extension(TestCustomElementReaderExtension::class);
            DB::query('DROP TABLE IF EXISTS "LegacyHeroBlock"');
        }
    }

    // ─── Scenario 10: SiteTree subclass page ──────────────────────

    public function testSubclassPageMigrationUsesConcreteParentClass(): void
    {
        $page = TestPage::create();
        $page->Title = 'Subclass Acceptance Page';
        $page->URLSegment = 'subclass-acceptance';
        $page->write();
        $pageId = (int) $page->ID;

        $areaId = 900;
        $this->seeder->seedPage($pageId, $areaId);

        // One row with two content elements
        $this->seeder->seedElement(9001, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(9001);
        $this->seeder->seedElement(9002, $areaId, self::CONTENT_CLASS, 2, [
            'Title' => 'Subclass Element',
            'ShowTitle' => 1,
            'SizeMD' => 6,
        ]);
        $this->seeder->seedContentMedia(9002, ['HTML' => '<p>Subclass content</p>']);
        $this->seeder->seedElement(9003, $areaId, self::CONTENT_CLASS, 3, [
            'SizeMD' => 6,
        ]);
        $this->seeder->seedContentMedia(9003, ['HTML' => '<p>Second</p>']);

        $this->runAllRowsInSection($pageId);

        $this->assertMigratedHierarchy($pageId, self::ZONE, Versioned::DRAFT, [
            [
                'rows' => [
                    [
                        'columns' => [
                            [
                                'gridDefault' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                'element' => [
                                    'title' => 'Subclass Element',
                                    'showTitle' => true,
                                    'html' => '<p>Subclass content</p>',
                                ],
                            ],
                            [
                                'gridDefault' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                'element' => [
                                    'html' => '<p>Second</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], TestPage::class);
    }

    // ─── Assertion helper ─────────────────────────────────────────

    /**
     * Assert the complete migrated hierarchy matches expected structure.
     *
     * @param list<array{
     *     extraClass?: string,
     *     rows: list<array{
     *         title?: string,
     *         extraClass?: string,
     *         columns: list<array{
     *             gridDefault: array{width: int, offset: int, visible: bool},
     *             gridOverrides?: array<string, array{width?: int, offset?: int, visible?: bool}>,
     *             element: array{
     *                 className?: class-string,
     *                 title?: string,
     *                 showTitle?: bool,
     *                 titleTag?: string,
     *                 titleClass?: string,
     *                 extraClass?: string,
     *                 html?: string,
     *                 mediaType?: string,
     *                 mediaImageID?: int,
     *                 mediaRatio?: string,
     *                 mediaPosition?: string,
     *                 contentColumns?: int,
     *                 verticalAlignment?: string,
     *                 gapSize?: int,
     *                 videoURL?: string,
     *                 videoCustomThumbnailID?: int,
     *             },
     *         }>,
     *     }>,
     * }> $expectedSections
     */
    /**
     * @param class-string $parentClass
     */
    private function assertMigratedHierarchy(
        int $pageId,
        string $zone,
        string $stage,
        array $expectedSections,
        string $parentClass = SiteTree::class,
    ): void {
        Versioned::withVersionedMode(function () use ($pageId, $zone, $stage, $expectedSections, $parentClass): void {
            Versioned::set_stage($stage);

            $sections = Section::get()->filter([
                'ParentID' => $pageId,
                'ParentClass' => $parentClass,
                'Zone' => $zone,
            ])->sort('Sort', 'ASC');

            $stageName = $stage === Versioned::LIVE ? 'Live' : 'Draft';
            self::assertCount(
                \count($expectedSections),
                $sections,
                \sprintf('[%s] Expected %d sections, got %d', $stageName, \count($expectedSections), $sections->count()),
            );

            foreach ($sections as $sectionIndex => $section) {
                $expected = $expectedSections[$sectionIndex];
                $sectionPath = \sprintf('[%s] Section %d', $stageName, $sectionIndex + 1);

                if (isset($expected['extraClass'])) {
                    self::assertSame($expected['extraClass'], $section->ExtraClass, "$sectionPath: extraClass");
                }

                $rows = Row::get()->filter([
                    'ParentID' => $section->ID,
                    'ParentClass' => Section::class,
                ])->sort('Sort', 'ASC');

                self::assertCount(
                    \count($expected['rows']),
                    $rows,
                    "$sectionPath: row count",
                );

                foreach ($rows as $rowIndex => $row) {
                    $expectedRow = $expected['rows'][$rowIndex];
                    $rowPath = "$sectionPath > Row " . ($rowIndex + 1);

                    if (isset($expectedRow['title'])) {
                        self::assertSame($expectedRow['title'], $row->Title, "$rowPath: title");
                    }
                    if (isset($expectedRow['extraClass'])) {
                        self::assertSame($expectedRow['extraClass'], $row->ExtraClass, "$rowPath: extraClass");
                    }

                    $columns = Column::get()->filter([
                        'ParentID' => $row->ID,
                        'ParentClass' => Row::class,
                    ])->sort('Sort', 'ASC');

                    self::assertCount(
                        \count($expectedRow['columns']),
                        $columns,
                        "$rowPath: column count",
                    );

                    foreach ($columns as $colIndex => $column) {
                        $expectedCol = $expectedRow['columns'][$colIndex];
                        $colPath = "$rowPath > Column " . ($colIndex + 1);

                        $this->assertColumnGridSettings($column, $expectedCol, $colPath);
                        $this->assertContentElement($column, $expectedCol['element'], $colPath);
                    }
                }
            }
        });
    }

    /**
     * Assert grid settings (default + overrides) on a column.
     *
     * @param array{gridDefault: array{width: int, offset: int, visible: bool}, gridOverrides?: array<string, array{width?: int, offset?: int, visible?: bool}>} $expectedCol
     */
    private function assertColumnGridSettings(Column $column, array $expectedCol, string $colPath): void
    {
        $settings = $column->getGridSettings();
        $expectedDefault = $expectedCol['gridDefault'];

        self::assertSame($expectedDefault['width'], $settings->default->width, "$colPath: default width");
        self::assertSame($expectedDefault['offset'], $settings->default->offset, "$colPath: default offset");
        self::assertSame($expectedDefault['visible'], $settings->default->visible, "$colPath: default visible");

        $expectedOverrides = $expectedCol['gridOverrides'] ?? [];

        foreach ($expectedOverrides as $vp => $override) {
            self::assertTrue($settings->hasOverride($vp), "$colPath: missing override for viewport '$vp'");
            $actual = $settings->getOverride($vp);
            self::assertNotNull($actual, "$colPath: override '$vp' is null");

            if (isset($override['width'])) {
                self::assertSame($override['width'], $actual->width, "$colPath > override '$vp': width");
            }
            if (isset($override['offset'])) {
                self::assertSame($override['offset'], $actual->offset, "$colPath > override '$vp': offset");
            }
            if (isset($override['visible'])) {
                self::assertSame($override['visible'], $actual->visible, "$colPath > override '$vp': visible");
            }
        }

        // No unexpected overrides
        foreach ($settings->overrides as $vp => $override) {
            self::assertArrayHasKey($vp, $expectedOverrides, "$colPath: unexpected override for viewport '$vp'");
        }
    }

    /**
     * Assert content element fields inside a column.
     *
     * @param array<string, mixed> $expectedEl
     */
    private function assertContentElement(Column $column, array $expectedEl, string $colPath): void
    {
        $elements = GridElement::get()->filter([
            'ParentID' => $column->ID,
            'ParentClass' => Column::class,
        ]);

        self::assertCount(1, $elements, "$colPath: expected 1 content element");
        $element = $elements->first();
        $elPath = "$colPath > Element";

        if (isset($expectedEl['className'])) {
            self::assertSame($expectedEl['className'], $element->ClassName, "$elPath: className");
        }
        if (isset($expectedEl['title'])) {
            self::assertSame($expectedEl['title'], $element->Title, "$elPath: title");
        }
        if (isset($expectedEl['showTitle'])) {
            self::assertSame($expectedEl['showTitle'], (bool) $element->ShowTitle, "$elPath: showTitle");
        }
        if (isset($expectedEl['titleTag'])) {
            self::assertSame($expectedEl['titleTag'], $element->TitleTag, "$elPath: titleTag");
        }
        if (isset($expectedEl['titleClass'])) {
            self::assertSame($expectedEl['titleClass'], $element->TitleClass, "$elPath: titleClass");
        }
        if (isset($expectedEl['extraClass'])) {
            self::assertSame($expectedEl['extraClass'], $element->ExtraClass, "$elPath: extraClass");
        }

        // ContentElement-specific fields (including BlockMediaExtension)
        if ($element instanceof ContentElement) {
            if (isset($expectedEl['html'])) {
                self::assertSame($expectedEl['html'], $element->HTML, "$elPath: html");
            }
            if (isset($expectedEl['mediaType'])) {
                self::assertSame($expectedEl['mediaType'], $element->MediaType, "$elPath: mediaType");
            }
            if (isset($expectedEl['mediaImageID'])) {
                self::assertSame($expectedEl['mediaImageID'], (int) $element->MediaImageID, "$elPath: mediaImageID");
            }
            if (isset($expectedEl['mediaRatio'])) {
                self::assertSame($expectedEl['mediaRatio'], $element->MediaRatio, "$elPath: mediaRatio");
            }
            if (isset($expectedEl['mediaPosition'])) {
                self::assertSame($expectedEl['mediaPosition'], $element->MediaPosition, "$elPath: mediaPosition");
            }
            if (isset($expectedEl['contentColumns'])) {
                self::assertSame($expectedEl['contentColumns'], (int) $element->ContentColumns, "$elPath: contentColumns");
            }
            if (isset($expectedEl['verticalAlignment'])) {
                self::assertSame($expectedEl['verticalAlignment'], $element->VerticalAlignment, "$elPath: verticalAlignment");
            }
            if (isset($expectedEl['gapSize'])) {
                self::assertSame($expectedEl['gapSize'], (int) $element->GapSize, "$elPath: gapSize");
            }
            if (isset($expectedEl['videoURL'])) {
                self::assertSame($expectedEl['videoURL'], $element->VideoURL, "$elPath: videoURL");
            }
            if (isset($expectedEl['videoCustomThumbnailID'])) {
                self::assertSame($expectedEl['videoCustomThumbnailID'], (int) $element->VideoCustomThumbnailID, "$elPath: videoCustomThumbnailID");
            }
        }

        // TestCustomElement-specific fields (custom project elements)
        if ($element instanceof TestCustomElement) {
            if (isset($expectedEl['subtitle'])) {
                self::assertSame($expectedEl['subtitle'], $element->Subtitle, "$elPath: subtitle");
            }
            if (isset($expectedEl['buttonText'])) {
                self::assertSame($expectedEl['buttonText'], $element->ButtonText, "$elPath: buttonText");
            }
        }
    }

    // ─── Migration runner helpers ─────────────────────────────────

    /**
     * @param array<string, string>|null $viewportKeyMap Override viewport key map (default: Bootstrap identity map)
     */
    private function runRowPerSection(int $pageId, ?string $defaultViewport = null, ?array $viewportKeyMap = null): void
    {
        $viewport = $defaultViewport ?? self::DEFAULT_VIEWPORT;
        $keyMap = $viewportKeyMap ?? self::VIEWPORT_KEY_MAP;
        $reader = new LegacyDataReader();
        $mapper = new FieldMapper();
        $grouper = new ElementGrouper();
        $strategy = new RowPerSectionStrategy($grouper, $mapper, $viewport, $keyMap);
        $service = new GridMigrationService($reader, $mapper, $strategy, new NullLogger());
        $service->run($viewport, self::ZONE, $keyMap, false, [$pageId]);
    }

    /**
     * @param array<string, string>|null $viewportKeyMap Override viewport key map (default: Bootstrap identity map)
     */
    private function runAllRowsInSection(int $pageId, ?string $defaultViewport = null, ?array $viewportKeyMap = null): void
    {
        $viewport = $defaultViewport ?? self::DEFAULT_VIEWPORT;
        $keyMap = $viewportKeyMap ?? self::VIEWPORT_KEY_MAP;
        $reader = new LegacyDataReader();
        $mapper = new FieldMapper();
        $grouper = new ElementGrouper();
        $strategy = new AllRowsInSectionStrategy($grouper, $mapper, $viewport, $keyMap, new NullLogger());
        $service = new GridMigrationService($reader, $mapper, $strategy, new NullLogger());
        $service->run($viewport, self::ZONE, $keyMap, false, [$pageId]);
    }

    private function getPageId(): int
    {
        return (int) $this->objFromFixture(SiteTree::class, 'test_page')->ID;
    }

    /**
     * Remove all records from GridElement and related tables to prevent leaking between tests.
     */
    private function cleanGridTables(): void
    {
        $tables = [
            'TestCustomElement', 'TestCustomElement_Live',
            'ContentElement', 'ContentElement_Live',
            'Column', 'Column_Live',
            'Row', 'Row_Live',
            'Section', 'Section_Live',
            'GridElement', 'GridElement_Live',
            'TestPage', 'TestPage_Live',
        ];

        $allTables = DB::table_list();

        foreach ($tables as $table) {
            if (\array_key_exists(\strtolower($table), $allTables)) {
                DB::query("DELETE FROM \"{$table}\"");
            }
        }
    }
}
