<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use Closure;
use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\HierarchyValidationService;
use WeDevelop\Grid\Validation\HierarchyValidatorInterface;
use WeDevelop\Grid\Value\ValidationErrorCode;

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

    /**
     * Each case builds an element and mutates it onto a disallowed parent,
     * returning the subject to validate. The closure resolves fixtures.
     *
     * @return iterable<string, array{Closure(self): GridElement}>
     */
    public static function invalidPlacementProvider(): iterable
    {
        yield 'row at page level' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $row->ParentID = $page->ID;
            $row->ParentClass = $page::class;
            return $row;
        }];
        yield 'column at page level' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $column = GridTreeFactory::column($row);
            $column->ParentID = $page->ID;
            $column->ParentClass = $page::class;
            return $column;
        }];
        yield 'content element at page level' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $column = GridTreeFactory::column($row);
            $content = GridTreeFactory::contentElement($column);
            $content->ParentID = $page->ID;
            $content->ParentClass = $page::class;
            return $content;
        }];
        yield 'section inside section' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $sectionA = GridTreeFactory::section($page);
            $sectionB = GridTreeFactory::section($page);
            $sectionB->ParentID = $sectionA->ID;
            $sectionB->ParentClass = $sectionA::class;
            return $sectionB;
        }];
        yield 'column inside section' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $column = GridTreeFactory::column($row);
            $column->ParentID = $section->ID;
            $column->ParentClass = $section::class;
            return $column;
        }];
        yield 'row inside column' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $column = GridTreeFactory::column($row);
            $row2 = GridTreeFactory::row($section);
            $row2->ParentID = $column->ID;
            $row2->ParentClass = $column::class;
            return $row2;
        }];
    }

    /**
     * @param Closure(self): GridElement $build
     */
    #[DataProvider('invalidPlacementProvider')]
    public function testInvalidPlacementFails(Closure $build): void
    {
        $result = $this->getService()->validate($build($this));

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

    /**
     * @return iterable<string, array{Closure(self): GridElement}>
     */
    public static function hierarchyViolationCodeProvider(): iterable
    {
        yield 'page-level violation' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $row->ParentID = $page->ID;
            $row->ParentClass = $page::class;
            return $row;
        }];
        yield 'parent violation' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $column = GridTreeFactory::column($row);
            $column->ParentID = $section->ID;
            $column->ParentClass = $section::class;
            return $column;
        }];
    }

    /**
     * @param Closure(self): GridElement $build
     */
    #[DataProvider('hierarchyViolationCodeProvider')]
    public function testViolationCarriesHierarchyViolationCode(Closure $build): void
    {
        $result = $this->getService()->validate($build($this));

        self::assertTrue($result->isErr());
        self::assertSame(ValidationErrorCode::HierarchyViolation, $result->errors()[0]->code);
    }
}
