<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Service\SharedBlockService;

/**
 * Seeding a library block with its root.
 *
 * Auto-scaffolding stays ON here, unlike the rest of the shared-block suite:
 * what the root's own write cascades below it is half of what this method
 * promises, and a block whose row has no column is as unusable as one with no
 * root at all.
 *
 * @see SharedBlockService::create()
 */
#[CoversClass(SharedBlockService::class)]
#[CoversClass(SharedBlock::class)]
final class SharedBlockCreateTest extends SapphireTest
{
    protected $usesDatabase = true;

    private SharedBlockService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->logInWithPermission('ADMIN');

        $this->service = Injector::inst()->get(SharedBlockService::class);
    }

    public function testASectionRootedBlockArrivesWithTheFullScaffold(): void
    {
        $block = $this->create(Section::class);

        $section = $block->getRootElement();
        self::assertInstanceOf(Section::class, $section);

        $row = $this->onlyChildOf($section);
        self::assertInstanceOf(Row::class, $row);
        self::assertInstanceOf(Column::class, $this->onlyChildOf($row));
    }

    public function testARowRootedBlockArrivesWithItsColumn(): void
    {
        $block = $this->create(Row::class);

        $row = $block->getRootElement();
        self::assertInstanceOf(Row::class, $row);
        self::assertInstanceOf(Column::class, $this->onlyChildOf($row));
    }

    public function testAColumnRootedBlockArrivesEmpty(): void
    {
        $block = $this->create(Column::class);

        $column = $block->getRootElement();
        self::assertInstanceOf(Column::class, $column);
        self::assertCount(0, $column->getChildren());
    }

    public function testALeafRootedBlockIsTheSingleElement(): void
    {
        $block = $this->create(ContentElement::class);

        self::assertInstanceOf(ContentElement::class, $block->getRootElement());
    }

    /**
     * Zone is placement data: it belongs to the reference that places the block
     * on a page, never to the block's own subtree.
     */
    public function testASectionRootedBlockCarriesNoZone(): void
    {
        $section = $this->create(Section::class)->getRootElement();

        self::assertInstanceOf(Section::class, $section);
        self::assertSame('', (string) $section->Zone);
    }

    public function testTheBlockHasExactlyOneRoot(): void
    {
        $block = $this->create(Section::class);

        self::assertSame(1, $block->RootElements()->count());
    }

    public function testEachBlockGetsItsOwnNumberedDefaultTitle(): void
    {
        self::assertSame('New shared block 1', (string) $this->create(Section::class)->Title);
        self::assertSame('New shared block 2', (string) $this->create(Row::class)->Title);
    }

    public function testAnExplicitTitleIsLeftAlone(): void
    {
        $block = SharedBlock::create();
        $block->Title = 'Hero banner';
        $block->write();

        self::assertSame('Hero banner', (string) $block->Title);
    }

    /**
     * The default is assigned on write, not rendered on read: the library
     * listing, the report and the placement picker all read the stored column.
     */
    public function testTheDefaultTitleIsPersisted(): void
    {
        $id = (int) $this->create(Section::class)->ID;

        self::assertSame('New shared block 1', (string) SharedBlock::get()->byID($id)?->Title);
    }

    public function testTheBlockAndItsRootAreDraftOnly(): void
    {
        $block = $this->create(Section::class);

        self::assertFalse($block->isPublished());
        self::assertFalse($block->getRootElement()?->isPublished());
    }

    /** @param class-string<\WeDevelop\Grid\Model\GridElement> $rootClass */
    private function create(string $rootClass): SharedBlock
    {
        $result = $this->service->create($rootClass);

        self::assertTrue($result->isOk(), 'create() failed: ' . implode(', ', array_map(
            static fn ($error): string => $error->message,
            $result->errors(),
        )));

        return $result->unwrap();
    }

    private function onlyChildOf(ContainerInterface $container): \WeDevelop\Grid\Model\GridElement
    {
        self::assertCount(1, $container->getChildren());

        $child = $container->getChildren()->first();
        self::assertNotNull($child);

        return $child;
    }
}
