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
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\ReorderValidator;
use WeDevelop\Grid\Value\ValidationErrorCode;

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

    public function testSameParentAlwaysPasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Validate reorder within the same parent (section)
        $result = $this->getValidator()->validate($row, $section);

        self::assertTrue($result->isOk());
    }

    /**
     * Each case builds a valid cross-parent relocation and returns the moved
     * element together with its new (allowed) target parent. The closure
     * receives the test case so it can resolve fixtures.
     *
     * @return iterable<string, array{Closure(self): array{GridElement, DataObject}}>
     */
    public static function validCrossParentMoveProvider(): iterable
    {
        yield 'section to another page' => [
            static function (self $test): array {
                $pageA = $test->objFromFixture(Page::class, 'test_page');
                $pageB = $test->objFromFixture(Page::class, 'test_page_2');
                $section = GridTreeFactory::section($pageA);

                return [$section, $pageB];
            },
        ];

        yield 'row to another section' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $sectionA = GridTreeFactory::section($page);
                $sectionB = GridTreeFactory::section($page);
                $row = GridTreeFactory::row($sectionA);

                return [$row, $sectionB];
            },
        ];

        yield 'column to another row' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $section = GridTreeFactory::section($page);
                $rowA = GridTreeFactory::row($section);
                $rowB = GridTreeFactory::row($section);
                $column = GridTreeFactory::column($rowA);

                return [$column, $rowB];
            },
        ];

        yield 'content to another column' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $section = GridTreeFactory::section($page);
                $row = GridTreeFactory::row($section);
                $columnA = GridTreeFactory::column($row);
                $columnB = GridTreeFactory::column($row);
                $content = GridTreeFactory::contentElement($columnA);

                return [$content, $columnB];
            },
        ];
    }

    /**
     * @param Closure(self): array{GridElement, DataObject} $buildMove
     */
    #[DataProvider('validCrossParentMoveProvider')]
    public function testValidCrossParentMovePasses(Closure $buildMove): void
    {
        [$element, $targetParent] = $buildMove($this);

        $result = $this->getValidator()->validate($element, $targetParent);

        self::assertTrue($result->isOk());
    }

    /**
     * @return iterable<string, array{Closure(self): array{GridElement, DataObject}}>
     */
    public static function invalidCrossParentMoveProvider(): iterable
    {
        yield 'row to page level' => [static function (self $test): array {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $pageB = $test->objFromFixture(Page::class, 'test_page_2');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            return [$row, $pageB];
        }];
        yield 'section into column' => [static function (self $test): array {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $column = GridTreeFactory::column($row);
            $sectionB = GridTreeFactory::section($page);
            return [$sectionB, $column];
        }];
    }

    /**
     * @param Closure(self): array{GridElement, DataObject} $buildMove
     */
    #[DataProvider('invalidCrossParentMoveProvider')]
    public function testInvalidCrossParentMoveFails(Closure $buildMove): void
    {
        [$element, $targetParent] = $buildMove($this);

        $result = $this->getValidator()->validate($element, $targetParent);

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

    public function testParentRejectedErrorParamsIncludeBothElementAndParent(): void
    {
        // Section moved into a Column — PARENT_REJECTED path.
        // Pins the `'element' => ..., 'parent' => ...` array shape: the ArrayItem
        // mutants replace `=>` with `>` (degenerating one pair into a boolean);
        // ArrayItemRemoval drops one key entirely.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $sectionB = GridTreeFactory::section($page);

        $result = $this->getValidator()->validate($sectionB, $column);

        self::assertTrue($result->isErr());
        $error = $result->errors()[0];
        self::assertSame(ReorderValidator::class . '.PARENT_REJECTED', $error->key);
        self::assertArrayHasKey('element', $error->params);
        self::assertArrayHasKey('parent', $error->params);
        self::assertSame($sectionB->singular_name(), $error->params['element']);
        self::assertSame($column->singular_name(), $error->params['parent']);
    }

    /**
     * @return iterable<string, array{Closure(self): array{GridElement, DataObject}}>
     */
    public static function hierarchyViolationCodeMoveProvider(): iterable
    {
        yield 'page-level move' => [static function (self $test): array {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $pageB = $test->objFromFixture(Page::class, 'test_page_2');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            return [$row, $pageB];
        }];
        yield 'parent-rejected move' => [static function (self $test): array {
            $page = $test->objFromFixture(Page::class, 'test_page');
            $section = GridTreeFactory::section($page);
            $row = GridTreeFactory::row($section);
            $column = GridTreeFactory::column($row);
            $sectionB = GridTreeFactory::section($page);
            return [$sectionB, $column];
        }];
    }

    /**
     * @param Closure(self): array{GridElement, DataObject} $buildMove
     */
    #[DataProvider('hierarchyViolationCodeMoveProvider')]
    public function testViolationCarriesHierarchyViolationCode(Closure $buildMove): void
    {
        [$element, $targetParent] = $buildMove($this);

        $result = $this->getValidator()->validate($element, $targetParent);

        self::assertTrue($result->isErr());
        self::assertSame(ValidationErrorCode::HierarchyViolation, $result->errors()[0]->code);
    }
}
