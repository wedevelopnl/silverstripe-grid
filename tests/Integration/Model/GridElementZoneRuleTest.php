<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Zone is non-empty exactly when an element sits directly on a page. A root
 * without one renders in no zone, and a nested element with one is invisible
 * to every zone read — both used to be stored silently.
 */
#[CoversClass(GridElement::class)]
final class GridElementZoneRuleTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    private const string MISSING = 'missing';

    private const string MISPLACED = 'misplaced';

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    /** @return iterable<string, array{class-string<GridElement>, string, string|null, string}> */
    public static function invalidZoneProvider(): iterable
    {
        yield 'Section on a page, empty zone' => [Section::class, 'page', '', self::MISSING];
        yield 'Section on a page, NULL zone' => [Section::class, 'page', null, self::MISSING];
        yield 'placement on a page, empty zone' => [SharedBlockReference::class, 'page', '', self::MISSING];
        yield 'placement on a page, NULL zone' => [SharedBlockReference::class, 'page', null, self::MISSING];
        yield 'Row in a Section, with a zone' => [Row::class, 'section', 'main', self::MISPLACED];
        yield 'Column in a Row, with a zone' => [Column::class, 'row', 'main', self::MISPLACED];
        yield 'content element in a Column, with a zone' => [ContentElement::class, 'column', 'main', self::MISPLACED];
        yield 'Section rooting a shared block, with a zone' => [Section::class, 'block', 'main', self::MISPLACED];
    }

    /** @param class-string<GridElement> $class */
    #[DataProvider('invalidZoneProvider')]
    public function testRefusesAZoneThatDoesNotMatchThePosition(
        string $class,
        string $parentKind,
        ?string $zone,
        string $expected,
    ): void {
        $parent = $this->parentOfKind($parentKind);

        // Back on for the element under test: a refused write must not leave
        // a scaffolded child behind either.
        $this->enableAutoScaffolding();

        $element = $class::create();
        $element->Title = 'Under test';
        $element->Zone = $zone;
        $element->ParentID = $parent->ID;
        $element->ParentClass = $parent::class;

        if ($element instanceof SharedBlockReference) {
            $block = GridTreeFactory::sharedBlock();
            GridTreeFactory::section($block, zone: '');
            $element->BlockID = $block->ID;
        }

        $countBefore = GridElement::get()->count();

        try {
            $element->write();
            self::fail('The write was expected to be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(
                [$this->expectedMessage($expected, $class, $zone)],
                array_column($exception->getResult()->getMessages(), 'message'),
            );
        }

        self::assertSame(0, (int) $element->ID, 'nothing is stored');
        self::assertSame($countBefore, GridElement::get()->count(), 'nothing is stored or scaffolded');
    }

    public function testAcceptsAZoneOnARootAndNoneBelowIt(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $tree = GridTreeFactory::treeFor($page);

        self::assertSame('main', $tree['section']->Zone);
        self::assertSame('', (string) $tree['content']->Zone);
    }

    public function testRefusesToPublishARootThatLostItsZone(): void
    {
        // The draft write that stored this row predates the rule, so only the
        // LIVE write of the publish can still catch it.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'Under test');
        DB::prepared_query('UPDATE "WeDevelop_Grid_GridElement" SET "Zone" = NULL WHERE "ID" = ?', [$section->ID]);

        try {
            $page->publishRecursive();
            self::fail('The publish was expected to be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(
                [$this->expectedMessage(self::MISSING, Section::class, null, (int) $section->ID)],
                array_column($exception->getResult()->getMessages(), 'message'),
            );
        }

        $live = Versioned::get_by_stage(Section::class, Versioned::LIVE)->byID($section->ID);
        self::assertNull($live, 'the zone-less root never reaches live');
    }

    private function parentOfKind(string $kind): DataObject
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        return match ($kind) {
            'page' => $page,
            'section' => GridTreeFactory::section($page),
            'row' => GridTreeFactory::row(GridTreeFactory::section($page)),
            'column' => GridTreeFactory::containerTree($page)['column'],
            'block' => GridTreeFactory::sharedBlock(),
        };
    }

    /** @param class-string<GridElement> $class */
    private function expectedMessage(string $expected, string $class, ?string $zone, int $id = 0): string
    {
        $type = $class::singleton()->getType();

        return $expected === self::MISSING
            ? sprintf(
                'A top-level block needs a page area, but %s "Under test" (ID %d) has none. '
                . 'Existing content like this is repaired by running "sake tasks:repair-grid-zone".',
                $type,
                $id,
            )
            : sprintf(
                'Only top-level blocks belong to a page area, but %s "Under test" (ID %d) is not directly '
                . 'on a page and has page area "%s".',
                $type,
                $id,
                $zone,
            );
    }
}
