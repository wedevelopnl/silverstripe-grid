<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use WeDevelop\Grid\Admin\SharedBlockAdmin;
use WeDevelop\Grid\Forms\GridFieldAddSharedBlockButton;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DenyBlockCreateExtension;
use WeDevelop\Grid\Tests\Integration\Support\DenyCreateExtension;

/**
 * The library's add control, exercised as the GridField component it is.
 *
 * These cover what used to be a JSON endpoint plus a React button: the shape
 * switch, both permission gates and the redirect now live in
 * {@see GridFieldAddSharedBlockButton::handleAction()}, and the menu is markup
 * rather than a serialised payload.
 */
#[CoversClass(GridFieldAddSharedBlockButton::class)]
final class GridFieldAddSharedBlockButtonTest extends SapphireTest
{
    protected $usesDatabase = true;

    private GridField $gridField;

    private GridFieldAddSharedBlockButton $component;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->logInWithPermission('ADMIN');

        $this->gridField = GridField::create('SharedBlocks', 'Shared blocks', SharedBlock::get());
        Form::create(
            SharedBlockAdmin::create(),
            'EditForm',
            FieldList::create($this->gridField),
            FieldList::create(),
        );

        $this->component = GridFieldAddSharedBlockButton::create();
    }

    /** @param array<string, mixed> $arguments */
    private function handle(array $arguments): mixed
    {
        return $this->component->handleAction($this->gridField, 'addsharedblock', $arguments, []);
    }

    private function fragment(): string
    {
        $fragments = $this->component->getHTMLFragments($this->gridField);

        return implode('', array_map(strval(...), $fragments));
    }

    /**
     * @param class-string<GridElement> $expectedRootClass
     */
    #[DataProvider('shapeProvider')]
    public function testEachShapeSeedsABlockRootedInItsOwnClass(string $shape, string $expectedRootClass): void
    {
        $this->handle(['root' => $shape]);

        $block = SharedBlock::get()->last();

        self::assertNotNull($block);
        self::assertInstanceOf($expectedRootClass, $block->getRootElement());
    }

    /**
     * @return iterable<string, array{string, class-string<GridElement>}>
     */
    public static function shapeProvider(): iterable
    {
        yield 'section' => ['section', Section::class];
        yield 'row' => ['row', Row::class];
        yield 'column' => ['column', Column::class];
        yield 'leaf class' => [ContentElement::class, ContentElement::class];
    }

    /**
     * The author is sent into the block's own form, not back to the listing:
     * the block exists only to be authored, and the editor is keyed by its id.
     */
    public function testCreatingRedirectsToTheNewBlocksEditor(): void
    {
        $response = $this->handle(['root' => 'section']);

        $block = SharedBlock::get()->last();

        self::assertNotNull($block);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            Director::absoluteURL((string) $block->getCMSEditLink()),
            $response->getHeader('Location'),
        );
    }

    /**
     * @param mixed $shape Anything the menu never offers.
     */
    #[DataProvider('invalidShapeProvider')]
    public function testAnUnofferedShapeIsRefusedAndCreatesNothing(mixed $shape): void
    {
        try {
            $this->handle(['root' => $shape]);
            self::fail('the action must refuse a shape it never offered');
        } catch (HTTPResponse_Exception $exception) {
            self::assertSame(400, $exception->getResponse()->getStatusCode());
        }

        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidShapeProvider(): iterable
    {
        yield 'unknown name' => ['banner'];
        yield 'empty string' => [''];
        yield 'not a string' => [1];
        yield 'a class that does not exist' => ['NonExistent\\Shape'];

        // A container is named by its shape, never by its class: the two routes
        // must not overlap, or 'section' would have two spellings.
        yield 'a container class' => [Section::class];

        // A reference carries the block it stands for, which this action has no
        // slot for, and a reference may never root a block at all.
        yield 'a shared block reference' => [SharedBlockReference::class];
    }

    public function testAMissingShapeIsRefused(): void
    {
        $this->expectException(HTTPResponse_Exception::class);

        $this->handle([]);
    }

    public function testAMemberWhoMayNotCreateBlocksIsRefused(): void
    {
        SharedBlock::add_extension(DenyBlockCreateExtension::class);

        try {
            $this->handle(['root' => 'section']);
            self::fail('the action must refuse a member who may not create blocks');
        } catch (HTTPResponse_Exception $exception) {
            self::assertSame(403, $exception->getResponse()->getStatusCode());
        }

        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    /**
     * Both gates, not one: a project may allow blocks in general and still
     * forbid creating the element the author picked to root this one with.
     */
    public function testAShapeTheProjectForbidsCreatingIsRefused(): void
    {
        ContentElement::add_extension(DenyCreateExtension::class);

        try {
            $this->handle(['root' => ContentElement::class]);
            self::fail('the action must refuse a root class the project forbids');
        } catch (HTTPResponse_Exception $exception) {
            self::assertSame(403, $exception->getResponse()->getStatusCode());
        }

        self::assertCount(0, SharedBlock::get(), 'no block may be created');
    }

    public function testTheControlOffersEveryShapeABlockMayRoot(): void
    {
        $fragment = $this->fragment();

        self::assertStringContainsString('Add new shared section', $fragment);
        self::assertStringContainsString('Add new shared row', $fragment);
        self::assertStringContainsString('Add new shared column', $fragment);
    }

    /**
     * The element types a Column accepts are exactly the shapes a leaf-rooted
     * block may take, and they are listed as menu items rather than serialised
     * to a client-side picker — which is what keeps this control off the
     * editor's bundle.
     */
    public function testTheControlListsTheElementTypesALeafRootedBlockMayTake(): void
    {
        // The label the menu shows is the element's own singular name, which
        // is already translated on this side — the reason there is nothing
        // left to serialise to a client-side picker.
        self::assertStringContainsString('Content element', $this->fragment());
    }

    public function testTheControlRequiresItsOwnScript(): void
    {
        Requirements::clear();

        $this->fragment();

        $required = implode(' ', array_keys(Requirements::backend()->getJavascript()));

        self::assertStringContainsString('client/js/shared-block-add.js', $required);
    }

    public function testNoControlIsRenderedForAMemberWhoMayNotCreateBlocks(): void
    {
        SharedBlock::add_extension(DenyBlockCreateExtension::class);

        self::assertSame('', $this->fragment());
    }
}
