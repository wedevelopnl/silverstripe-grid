<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use Closure;
use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\HierarchyValidationService;
use WeDevelop\Grid\Validation\PlacementRulesTrait;
use WeDevelop\Grid\Validation\ReorderValidator;
use WeDevelop\Grid\Value\ValidationErrorCode;

/**
 * The placement matrix for shared blocks: a reference is judged by its block's
 * root class ("effective class"), never by SharedBlockReference itself, and no
 * reference may live inside a shared subtree.
 */
#[CoversTrait(PlacementRulesTrait::class)]
#[CoversClass(ReorderValidator::class)]
final class SharedBlockPlacementTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableAutoScaffolding();

        Versioned::set_stage(Versioned::DRAFT);
    }

    private function getValidator(): ReorderValidatorInterface
    {
        return Injector::inst()->get(ReorderValidatorInterface::class);
    }

    /**
     * A block whose subtree is rooted at $rootType, plus an unwritten reference
     * to it. Unwritten on purpose: writing runs the very rules under test.
     *
     * @param 'section'|'row'|'column'|'element'|'empty' $rootType
     */
    private function referenceTo(string $rootType): SharedBlockReference
    {
        $block = GridTreeFactory::sharedBlock();

        if ($rootType !== 'empty') {
            $this->buildBlockSubtree($block, $rootType);
        }

        $reference = SharedBlockReference::create();
        $reference->BlockID = $block->ID;

        return $reference;
    }

    /** @param 'section'|'row'|'column'|'element' $rootType */
    private function buildBlockSubtree(SharedBlock $block, string $rootType): GridElement
    {
        // Each root type is written directly under the block: a block holds one
        // subtree of any shape, not necessarily a full Section > Row > Column.
        if ($rootType === 'section') {
            return GridTreeFactory::section($block, zone: '');
        }

        if ($rootType === 'row') {
            $row = Row::create();
            $row->ParentID = $block->ID;
            $row->ParentClass = SharedBlock::class;
            $row->write();

            return $row;
        }

        if ($rootType === 'column') {
            $column = Column::create();
            $column->ParentID = $block->ID;
            $column->ParentClass = SharedBlock::class;
            $column->write();

            return $column;
        }

        $leaf = \WeDevelop\Grid\Model\ContentElement::create();
        $leaf->ParentID = $block->ID;
        $leaf->ParentClass = SharedBlock::class;
        $leaf->write();

        return $leaf;
    }

    /** A page-local Section > Row > Column chain, returning the column. */
    private function localColumn(Page $page): Column
    {
        ['column' => $column] = GridTreeFactory::containerTree($page);

        return $column;
    }

    /**
     * Matrix rows 1-6 and 11-12: which placements the effective class allows.
     *
     * @return iterable<string, array{Closure(self): array{GridElement, DataObject}, bool, string|null}>
     */
    public static function placementMatrixProvider(): iterable
    {
        yield 'row 1: section-rooted reference at page root' => [
            static function (self $test): array {
                return [$test->referenceTo('section'), $test->objFromFixture(Page::class, 'test_page')];
            },
            true,
            null,
        ];

        yield 'row 2: row-rooted reference at page root' => [
            static function (self $test): array {
                return [$test->referenceTo('row'), $test->objFromFixture(Page::class, 'test_page')];
            },
            false,
            'PAGE_LEVEL_REJECTED',
        ];

        yield 'row 3: row-rooted reference inside a section' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');

                return [$test->referenceTo('row'), GridTreeFactory::section($page)];
            },
            true,
            null,
        ];

        yield 'row 4: column-rooted reference inside a row' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $row = GridTreeFactory::row(GridTreeFactory::section($page));

                return [$test->referenceTo('column'), $row];
            },
            true,
            null,
        ];

        yield 'row 5: leaf-rooted reference inside a column' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');

                return [$test->referenceTo('element'), $test->localColumn($page)];
            },
            true,
            null,
        ];

        yield 'row 6: section-rooted reference inside a column' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');

                return [$test->referenceTo('section'), $test->localColumn($page)];
            },
            false,
            'PARENT_REJECTED',
        ];

        yield 'row 7: reference to an empty block at page root' => [
            static function (self $test): array {
                return [$test->referenceTo('empty'), $test->objFromFixture(Page::class, 'test_page')];
            },
            false,
            'BLOCK_EMPTY',
        ];

        yield 'row 7b: reference to an empty block inside a column' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');

                return [$test->referenceTo('empty'), $test->localColumn($page)];
            },
            false,
            'BLOCK_EMPTY',
        ];

        yield 'row 11: plain section at page root' => [
            static function (self $test): array {
                $pageA = $test->objFromFixture(Page::class, 'test_page');
                $pageB = $test->objFromFixture(Page::class, 'test_page_2');

                return [GridTreeFactory::section($pageA), $pageB];
            },
            true,
            null,
        ];

        yield 'row 12: plain content element inside a column' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $columnA = $test->localColumn($page);
                ['column' => $columnB] = GridTreeFactory::containerTree($page);

                return [GridTreeFactory::contentElement($columnA), $columnB];
            },
            true,
            null,
        ];
    }

    /**
     * @param Closure(self): array{GridElement, DataObject} $build
     */
    #[DataProvider('placementMatrixProvider')]
    public function testPlacementMatrix(Closure $build, bool $expectOk, ?string $expectedKeySuffix): void
    {
        [$element, $parent] = $build($this);

        $result = $this->getValidator()->validate($element, $parent);

        if ($expectOk) {
            self::assertTrue($result->isOk(), 'placement should be allowed');

            return;
        }

        self::assertTrue($result->isErr(), 'placement should be rejected');
        self::assertSame(ReorderValidator::class . '.' . $expectedKeySuffix, $result->errors()[0]->key);
        self::assertSame(ValidationErrorCode::HierarchyViolation, $result->errors()[0]->code);
    }

    public function testReferenceRejectedInsideSharedSubtree(): void
    {
        // Matrix row 8: no nesting, anywhere inside another block's subtree.
        $hostBlock = GridTreeFactory::sharedBlock();
        $hostSection = GridTreeFactory::section($hostBlock, zone: '');
        $hostColumn = GridTreeFactory::column(GridTreeFactory::row($hostSection));

        $result = $this->getValidator()->validate($this->referenceTo('element'), $hostColumn);

        self::assertTrue($result->isErr());
        self::assertSame(ReorderValidator::class . '.SHARED_NESTING', $result->errors()[0]->key);
    }

    public function testReferenceRejectedDirectlyUnderABlock(): void
    {
        // Matrix row 10: a reference may not root a block either.
        $block = GridTreeFactory::sharedBlock();

        $result = $this->getValidator()->validate($this->referenceTo('section'), $block);

        self::assertTrue($result->isErr());
        self::assertSame(ReorderValidator::class . '.SHARED_NESTING', $result->errors()[0]->key);
    }

    /**
     * Matrix row 9: a block holds one subtree of any shape, so any element class
     * may root it. This is a write-time rule — convert-to-shared re-parents an
     * existing element onto the block — and deliberately NOT a reorder-time one,
     * where {@see testReorderContextMatrix} case A refuses the same move.
     *
     * @return iterable<string, array{Closure(self): GridElement}>
     */
    public static function blockRootProvider(): iterable
    {
        yield 'a section may root a block' => [static function (self $test): GridElement {
            return GridTreeFactory::section($test->objFromFixture(Page::class, 'test_page'));
        }];

        yield 'a leaf element may root a block' => [static function (self $test): GridElement {
            $page = $test->objFromFixture(Page::class, 'test_page');

            return GridTreeFactory::contentElement($test->localColumn($page));
        }];
    }

    /**
     * @param Closure(self): GridElement $build
     */
    #[DataProvider('blockRootProvider')]
    public function testAnyElementClassMayRootABlockAtWriteTime(Closure $build): void
    {
        $block = GridTreeFactory::sharedBlock();

        $element = $build($this);
        $element->ParentID = $block->ID;
        $element->ParentClass = SharedBlock::class;

        $result = Injector::inst()->get(HierarchyValidationService::class)->validate($element);

        self::assertTrue($result->isOk());
    }

    // --- An emptied block ---------------------------------------------------
    //
    // Refusing to PLACE an empty block is a placement decision. Refusing to
    // WRITE an already-placed reference whose block was later emptied is not:
    // it made every consuming page unpublishable, because publish writes each
    // owned root element to the live stage and that write runs validation.

    public function testWriteTimeValidationAcceptsAPlacementWhoseBlockWasEmptied(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $block = GridTreeFactory::sharedBlock();
        $root = GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);

        // No author path empties a block any more — GridElement::canDelete()
        // refuses the root — but the ORM has no permission gate, so migrations,
        // dev tasks and legacy data can still produce this state. It must not
        // take the consuming pages down with it.
        $root->delete();

        self::assertNull(
            $reference->getEffectiveRootClass(),
            'precondition: the block resolves no root class',
        );

        $result = Injector::inst()->get(HierarchyValidationService::class)->validate($reference);

        self::assertTrue($result->isOk(), 'an emptied block must not block the page');
    }

    public function testAnEmptiedBlockDoesNotBreakPagePublish(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $block = GridTreeFactory::sharedBlock();
        $root = GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);

        $root->delete();

        // publishRecursive walks $owns['GridRoots'] and writes each root element
        // to LIVE; that write revalidates, which is where BLOCK_EMPTY used to
        // abort the whole publish.
        $page->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);
        self::assertNotNull(
            SharedBlockReference::get()->byID((int) $reference->ID),
            'the placement must reach live even with nothing to render',
        );
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testPlacingAnEmptyBlockIsStillRefused(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $result = $this->getValidator()->validate($this->referenceTo('empty'), $page);

        self::assertTrue($result->isErr());
        self::assertSame(ReorderValidator::class . '.BLOCK_EMPTY', $result->errors()[0]->key);
    }

    public function testWriteTimeValidationRejectsNestedReference(): void
    {
        // The same rule at write time, through the other user of the trait.
        $hostBlock = GridTreeFactory::sharedBlock();
        $hostColumn = GridTreeFactory::column(
            GridTreeFactory::row(GridTreeFactory::section($hostBlock, zone: '')),
        );

        $reference = $this->referenceTo('element');
        $reference->ParentID = $hostColumn->ID;
        $reference->ParentClass = Column::class;

        $result = Injector::inst()->get(HierarchyValidationService::class)->validate($reference);

        self::assertTrue($result->isErr());
        self::assertSame(HierarchyValidationService::class . '.SHARED_NESTING', $result->errors()[0]->key);
    }

    /**
     * Reorder context matrix A-E: content may never cross a shared boundary,
     * in either direction, regardless of whether the placement itself is legal.
     *
     * @return iterable<string, array{Closure(self): array{GridElement, DataObject}, bool}>
     */
    public static function reorderContextProvider(): iterable
    {
        yield 'A: local element into a block' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $section = GridTreeFactory::section($page);
                $block = GridTreeFactory::sharedBlock();

                return [$section, $block];
            },
            false,
        ];

        yield 'B: shared element out to a page' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $block = GridTreeFactory::sharedBlock();
                $section = GridTreeFactory::section($block, zone: '');

                return [$section, $page];
            },
            false,
        ];

        yield 'C: within the same block' => [
            static function (self $test): array {
                $block = GridTreeFactory::sharedBlock();
                $row = GridTreeFactory::row(GridTreeFactory::section($block, zone: ''));
                $columnA = GridTreeFactory::column($row);
                $columnB = GridTreeFactory::column($row);
                $leaf = GridTreeFactory::contentElement($columnA);

                return [$leaf, $columnB];
            },
            true,
        ];

        yield 'D: between two different blocks' => [
            static function (self $test): array {
                $blockB = GridTreeFactory::sharedBlock();
                $rowB = GridTreeFactory::row(GridTreeFactory::section($blockB, zone: ''));

                $blockC = GridTreeFactory::sharedBlock();
                $rowC = GridTreeFactory::row(GridTreeFactory::section($blockC, zone: ''));

                return [GridTreeFactory::column($rowB), $rowC];
            },
            false,
        ];

        yield 'E: within page-local content' => [
            static function (self $test): array {
                $page = $test->objFromFixture(Page::class, 'test_page');
                $sectionA = GridTreeFactory::section($page);
                $sectionB = GridTreeFactory::section($page);

                return [GridTreeFactory::row($sectionA), $sectionB];
            },
            true,
        ];
    }

    /**
     * @param Closure(self): array{GridElement, DataObject} $build
     */
    #[DataProvider('reorderContextProvider')]
    public function testReorderContextMatrix(Closure $build, bool $expectOk): void
    {
        [$element, $targetParent] = $build($this);

        $result = $this->getValidator()->validate($element, $targetParent);

        if ($expectOk) {
            self::assertTrue($result->isOk());

            return;
        }

        self::assertTrue($result->isErr());
        self::assertSame(ReorderValidator::class . '.SHARED_BOUNDARY', $result->errors()[0]->key);
        self::assertSame(ValidationErrorCode::HierarchyViolation, $result->errors()[0]->code);
    }

    public function testFreshElementSkipsTheContextCheck(): void
    {
        // An unwritten element has no source context yet; placement rules alone
        // decide where it may land, exactly as before shared blocks existed.
        $block = GridTreeFactory::sharedBlock();

        $section = Section::create();
        $section->Title = 'Fresh';

        $result = $this->getValidator()->validate($section, $block);

        self::assertTrue($result->isOk());
    }
}
