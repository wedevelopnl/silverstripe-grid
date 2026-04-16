<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\ReorderValidator;

#[CoversClass(ReorderValidator::class)]
final class ReorderValidatorTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);
    }

    private function getValidator(): ReorderValidatorInterface
    {
        return Injector::inst()->get(ReorderValidatorInterface::class);
    }

    // -- Same-parent moves ------------------------------------------------

    public function testSameParentAlwaysPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Validate reorder within the same parent (section)
        $result = $this->getValidator()->validate($row, $section);

        self::assertTrue($result->isOk());
    }

    // -- Valid cross-parent moves -----------------------------------------

    public function testCrossParentSectionToPagePasses(): void
    {
        $pageA = $this->objFromFixture(Page::class, 'test_page');
        $pageB = $this->objFromFixture(Page::class, 'test_page_2');
        $section = GridTreeFactory::section($pageA);

        // Move section from pageA to pageB
        $result = $this->getValidator()->validate($section, $pageB);

        self::assertTrue($result->isOk());
    }

    public function testCrossParentRowToSectionPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($sectionA);

        // Move row from sectionA to sectionB
        $result = $this->getValidator()->validate($row, $sectionB);

        self::assertTrue($result->isOk());
    }

    public function testCrossParentColumnToRowPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $rowA = GridTreeFactory::row($section);
        $rowB = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($rowA);

        // Move column from rowA to rowB
        $result = $this->getValidator()->validate($column, $rowB);

        self::assertTrue($result->isOk());
    }

    public function testCrossParentContentToColumnPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $columnA = GridTreeFactory::column($row);
        $columnB = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($columnA);

        // Move content from columnA to columnB
        $result = $this->getValidator()->validate($content, $columnB);

        self::assertTrue($result->isOk());
    }

    // -- Invalid cross-parent moves ---------------------------------------

    public function testCrossParentRowToPageFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageB = $this->objFromFixture(Page::class, 'test_page_2');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Move row to page level — violates can_be_root: false
        $result = $this->getValidator()->validate($row, $pageB);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testCrossParentSectionToColumnFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $sectionB = GridTreeFactory::section($page);

        // Move section into a column — Section is disallowed in Column
        $result = $this->getValidator()->validate($sectionB, $column);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testViolationErrorHasTranslationKey(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageB = $this->objFromFixture(Page::class, 'test_page_2');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Row to page level — triggers PAGE_LEVEL_REJECTED
        $result = $this->getValidator()->validate($row, $pageB);

        self::assertTrue($result->isErr());

        $error = $result->errors()[0];
        self::assertSame(ReorderValidator::class . '.PAGE_LEVEL_REJECTED', $error->key);
        self::assertArrayHasKey('element', $error->params);
    }
}
