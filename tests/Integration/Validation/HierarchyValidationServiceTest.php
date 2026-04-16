<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\HierarchyValidationService;
use WeDevelop\Grid\Validation\HierarchyValidatorInterface;

#[CoversClass(HierarchyValidationService::class)]
final class HierarchyValidationServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);
    }

    private function getService(): HierarchyValidatorInterface
    {
        return Injector::inst()->get(HierarchyValidatorInterface::class);
    }

    // -- Valid placements --------------------------------------------------

    public function testSectionAtPageLevelPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $result = $this->getService()->validate($section);

        self::assertTrue($result->isOk());
    }

    public function testRowInsideSectionPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $result = $this->getService()->validate($row);

        self::assertTrue($result->isOk());
    }

    public function testColumnInsideRowPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $result = $this->getService()->validate($column);

        self::assertTrue($result->isOk());
    }

    public function testContentElementInsideColumnPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column);

        $result = $this->getService()->validate($content);

        self::assertTrue($result->isOk());
    }

    public function testOrphanElementPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        // Detach from parent without writing
        $section->ParentID = 0;
        $section->ParentClass = '';

        $result = $this->getService()->validate($section);

        self::assertTrue($result->isOk());
    }

    // -- Invalid placements ------------------------------------------------

    public function testRowAtPageLevelFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        // Create Row with valid parent first, then mutate to invalid placement
        $row = GridTreeFactory::row($section);
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;

        $result = $this->getService()->validate($row);

        self::assertTrue($result->isErr());
    }

    public function testColumnAtPageLevelFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Create Column with valid parent first, then mutate
        $column = GridTreeFactory::column($row);
        $column->ParentID = $page->ID;
        $column->ParentClass = $page::class;

        $result = $this->getService()->validate($column);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testContentElementAtPageLevelFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        // Create ContentElement with valid parent first, then mutate
        $content = GridTreeFactory::contentElement($column);
        $content->ParentID = $page->ID;
        $content->ParentClass = $page::class;

        $result = $this->getService()->validate($content);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testSectionInsideSectionFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);

        // Mutate B to be child of A
        $sectionB->ParentID = $sectionA->ID;
        $sectionB->ParentClass = $sectionA::class;

        $result = $this->getService()->validate($sectionB);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testColumnInsideSectionFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Create Column with valid parent first, then mutate
        $column = GridTreeFactory::column($row);
        $column->ParentID = $section->ID;
        $column->ParentClass = $section::class;

        $result = $this->getService()->validate($column);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testRowInsideColumnFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        // Create a second Row with valid parent, then mutate into Column
        $row2 = GridTreeFactory::row($section);
        $row2->ParentID = $column->ID;
        $row2->ParentClass = $column::class;

        $result = $this->getService()->validate($row2);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testViolationErrorHasTranslationKey(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        // Row cannot be placed at page level — triggers PAGE_LEVEL_REJECTED
        $row = GridTreeFactory::row($section);
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;

        $result = $this->getService()->validate($row);

        self::assertTrue($result->isErr());

        $error = $result->errors()[0];
        self::assertSame(HierarchyValidationService::class . '.PAGE_LEVEL_REJECTED', $error->key);
        self::assertArrayHasKey('element', $error->params);

        // Verify PARENT_REJECTED also carries a key — Column inside Section is disallowed
        $row2 = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row2);
        $column->ParentID = $section->ID;
        $column->ParentClass = $section::class;

        $parentResult = $this->getService()->validate($column);

        self::assertTrue($parentResult->isErr());

        $parentError = $parentResult->errors()[0];
        self::assertSame(HierarchyValidationService::class . '.PARENT_REJECTED', $parentError->key);
        self::assertArrayHasKey('element', $parentError->params);
        self::assertArrayHasKey('parent', $parentError->params);
    }
}
