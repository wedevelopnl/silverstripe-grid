<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\RequestBodyParser;
use WeDevelop\Grid\Tests\Unit\Support\DirectGridElementStub;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\CreateContentRequest;
use WeDevelop\Grid\Value\CreateElementRequest;
use WeDevelop\Grid\Value\CreateSharedBlockRequest;
use WeDevelop\Grid\Value\DuplicateToRequest;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\PlaceSharedBlockRequest;
use WeDevelop\Grid\Value\ReorderRequest;
use WeDevelop\Grid\Value\ResetGridSettingsOverridesRequest;
use WeDevelop\Grid\Value\UpdateGridSettingsRequest;

#[CoversClass(RequestBodyParser::class)]
#[CoversClass(CreateContentRequest::class)]
#[CoversClass(CreateElementRequest::class)]
#[CoversClass(CreateSharedBlockRequest::class)]
#[CoversClass(DuplicateToRequest::class)]
#[CoversClass(NodeRef::class)]
#[CoversClass(PlaceSharedBlockRequest::class)]
#[CoversClass(ReorderRequest::class)]
#[CoversClass(ResetGridSettingsOverridesRequest::class)]
#[CoversClass(UpdateGridSettingsRequest::class)]
final class RequestBodyParserTest extends TestCase
{
    private RequestBodyParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RequestBodyParser(new GridAdapterStub());
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('createBodyValidProvider')]
    public function testParseCreateBodyValid(
        array $input,
        ContainerType $expectedType,
        NodeRef $expectedParent,
        ?int $expectedInsertAfter,
        string $expectedZone,
        bool $expectedInsertAtStart,
    ): void {
        $result = $this->parser->parseCreateBody($input);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(CreateElementRequest::class, $request);
        self::assertSame($expectedType, $request->containerType);
        self::assertTrue($request->parent->equals($expectedParent));
        self::assertSame($expectedInsertAfter, $request->insertAfterElementID);
        self::assertSame($expectedZone, $request->zone);
        self::assertSame($expectedInsertAtStart, $request->insertAtStart);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ContainerType, NodeRef, ?int, string, bool}>
     */
    public static function createBodyValidProvider(): iterable
    {
        yield 'section under page' => [
            ['containerType' => 'section', 'parent' => ['type' => 'page', 'id' => 1], 'insertAfterElementID' => 5, 'zone' => 'sidebar'],
            ContainerType::Section, new NodeRef(NodeType::Page, 1), 5, 'sidebar', false,
        ];

        yield 'null insertAfterElementID' => [
            ['containerType' => 'row', 'parent' => ['type' => 'section', 'id' => 5], 'insertAfterElementID' => null],
            ContainerType::Row, new NodeRef(NodeType::Section, 5), null, 'main', false,
        ];

        yield 'default zone' => [
            ['containerType' => 'column', 'parent' => ['type' => 'row', 'id' => 3]],
            ContainerType::Column, new NodeRef(NodeType::Row, 3), null, 'main', false,
        ];

        yield 'insertAtStart' => [
            ['containerType' => 'column', 'parent' => ['type' => 'row', 'id' => 3], 'insertAtStart' => true],
            ContainerType::Column, new NodeRef(NodeType::Row, 3), null, 'main', true,
        ];

        // Boundary: insertAfterElementID = 1 is the smallest accepted value.
        // The `$afterElementID < 1` guard must reject 0 but accept 1; a mutant
        // widening it to `<= 1` would wrongly reject this case.
        yield 'boundary insertAfterElementID of one' => [
            ['containerType' => 'row', 'parent' => ['type' => 'section', 'id' => 5], 'insertAfterElementID' => 1],
            ContainerType::Row, new NodeRef(NodeType::Section, 5), 1, 'main', false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('createBodyErrorProvider')]
    public function testParseCreateBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseCreateBody($input);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function createBodyErrorProvider(): iterable
    {
        yield 'missing containerType' => [
            ['parent' => ['type' => 'page', 'id' => 1]],
            'Invalid or missing containerType.',
        ];

        yield 'invalid containerType' => [
            ['containerType' => 'invalid', 'parent' => ['type' => 'page', 'id' => 1]],
            'Invalid or missing containerType.',
        ];

        yield 'non-string containerType' => [
            ['containerType' => 123, 'parent' => ['type' => 'page', 'id' => 1]],
            'Invalid or missing containerType.',
        ];

        yield 'missing parent' => [
            ['containerType' => 'section'],
            'parent: ',
        ];

        yield 'parent with zero id' => [
            ['containerType' => 'section', 'parent' => ['type' => 'page', 'id' => 0]],
            'parent: ',
        ];

        yield 'parent with unknown type' => [
            ['containerType' => 'section', 'parent' => ['type' => 'foo', 'id' => 1]],
            'parent: ',
        ];

        yield 'non-int insertAfterElementID' => [
            ['containerType' => 'section', 'parent' => ['type' => 'page', 'id' => 1], 'insertAfterElementID' => 'abc'],
            'insertAfterElementID must be a positive integer or null.',
        ];

        yield 'zero insertAfterElementID' => [
            ['containerType' => 'section', 'parent' => ['type' => 'page', 'id' => 1], 'insertAfterElementID' => 0],
            'insertAfterElementID must be a positive integer or null.',
        ];

        yield 'non-bool insertAtStart' => [
            ['containerType' => 'column', 'parent' => ['type' => 'row', 'id' => 3], 'insertAtStart' => 'yes'],
            'insertAtStart must be a boolean.',
        ];

        yield 'insertAtStart combined with insertAfterElementID' => [
            ['containerType' => 'column', 'parent' => ['type' => 'row', 'id' => 3], 'insertAfterElementID' => 5, 'insertAtStart' => true],
            'insertAtStart and insertAfterElementID are mutually exclusive.',
        ];

        yield 'empty zone' => [
            ['containerType' => 'section', 'parent' => ['type' => 'page', 'id' => 1], 'zone' => ''],
            'zone must be a non-empty string.',
        ];
    }

    public function testParseCreateContentBodyValid(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parent' => ['type' => 'column', 'id' => 1],
            'insertAfterElementID' => 1,
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(CreateContentRequest::class, $request);
        self::assertSame(ContentElement::class, $request->className);
        self::assertTrue($request->parent->equals(new NodeRef(NodeType::Column, 1)));
        self::assertSame(1, $request->insertAfterElementID);
    }

    /**
     * A column accepts any concrete non-container element, so a custom block that
     * extends GridElement directly — the documented route for blocks that need no
     * HTML body — must parse just like a ContentElement subclass.
     */
    public function testParseCreateContentBodyAcceptsDirectGridElementSubclass(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => DirectGridElementStub::class,
            'parent' => ['type' => 'column', 'id' => 1],
        ]);

        self::assertTrue($result->isOk());
        self::assertSame(DirectGridElementStub::class, $result->unwrap()->className);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('createContentBodyErrorProvider')]
    public function testParseCreateContentBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseCreateContentBody($input);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function createContentBodyErrorProvider(): iterable
    {
        $validParent = ['type' => 'column', 'id' => 1];

        yield 'non-string className' => [
            ['className' => 123, 'parent' => $validParent],
            'className must be a string.',
        ];

        yield 'non-existent class' => [
            ['className' => 'NonExistent\\Class', 'parent' => $validParent],
            'className does not refer to an existing class.',
        ];

        yield 'shared block reference' => [
            ['className' => SharedBlockReference::class, 'parent' => $validParent],
            'Shared blocks are placed through their own endpoint.',
        ];

        yield 'not a GridElement at all' => [
            ['className' => stdClass::class, 'parent' => $validParent],
            'className is not an element type a column can hold.',
        ];

        yield 'a container class' => [
            ['className' => Section::class, 'parent' => $validParent],
            'className is not an element type a column can hold.',
        ];

        yield 'the GridElement base itself' => [
            ['className' => GridElement::class, 'parent' => $validParent],
            'className is not an element type a column can hold.',
        ];

        yield 'missing parent' => [
            ['className' => ContentElement::class],
            'parent: ',
        ];

        yield 'parent with zero id' => [
            ['className' => ContentElement::class, 'parent' => ['type' => 'column', 'id' => 0]],
            'parent: ',
        ];

        yield 'non-int insertAfterElementID' => [
            ['className' => ContentElement::class, 'parent' => $validParent, 'insertAfterElementID' => 'abc'],
            'insertAfterElementID must be a positive integer or null.',
        ];
    }

    /**
     * @param class-string<GridElement> $expectedClass
     */
    #[DataProvider('createSharedBlockContainerProvider')]
    public function testParseCreateSharedBlockBodyResolvesAContainerTypeToItsClass(
        string $containerType,
        string $expectedClass,
    ): void {
        $result = $this->parser->parseCreateSharedBlockBody(['containerType' => $containerType]);

        self::assertTrue($result->isOk());
        self::assertSame($expectedClass, $result->unwrap()->rootClass);
    }

    /**
     * @return iterable<string, array{string, class-string<GridElement>}>
     */
    public static function createSharedBlockContainerProvider(): iterable
    {
        yield 'section' => ['section', Section::class];
        yield 'row' => ['row', Row::class];
        yield 'column' => ['column', Column::class];
    }

    /**
     * A leaf-rooted block is seeded with a single content element, judged by the
     * same rule a column uses — so anything a column accepts may root a block.
     */
    public function testParseCreateSharedBlockBodyAcceptsALeafClass(): void
    {
        $result = $this->parser->parseCreateSharedBlockBody(['className' => ContentElement::class]);

        self::assertTrue($result->isOk());
        self::assertSame(ContentElement::class, $result->unwrap()->rootClass);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('createSharedBlockBodyErrorProvider')]
    public function testParseCreateSharedBlockBodyRejectsInvalidInput(
        array $input,
        string $expectedMessage,
    ): void {
        $result = $this->parser->parseCreateSharedBlockBody($input);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function createSharedBlockBodyErrorProvider(): iterable
    {
        $exactlyOne = 'Exactly one of containerType or className is required.';

        yield 'no discriminator' => [[], $exactlyOne];

        yield 'both discriminators' => [
            ['containerType' => 'section', 'className' => ContentElement::class],
            $exactlyOne,
        ];

        yield 'unknown container type' => [
            ['containerType' => 'block'],
            'Invalid or missing containerType.',
        ];

        yield 'non-string container type' => [
            ['containerType' => 1],
            'Invalid or missing containerType.',
        ];

        yield 'non-string className' => [
            ['className' => 123],
            'className must be a string.',
        ];

        yield 'non-existent class' => [
            ['className' => 'NonExistent\\Class'],
            'className does not refer to an existing class.',
        ];

        // A container is named by containerType, never by class: the two routes
        // must not overlap, or 'section' would have two spellings.
        yield 'a container class' => [
            ['className' => Section::class],
            'className is not an element type a column can hold.',
        ];

        yield 'shared block reference' => [
            ['className' => SharedBlockReference::class],
            'Shared blocks are placed through their own endpoint.',
        ];
    }

    public function testParseReorderBodyValidSameContainer(): void
    {
        $result = $this->parser->parseReorderBody([
            'element' => ['type' => 'row', 'id' => 17],
            'parent' => ['type' => 'section', 'id' => 1],
            'after' => ['type' => 'row', 'id' => 16],
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(ReorderRequest::class, $request);
        self::assertTrue($request->element->equals(new NodeRef(NodeType::Row, 17)));
        self::assertTrue($request->parent->equals(new NodeRef(NodeType::Section, 1)));
        self::assertNotNull($request->after);
        self::assertTrue($request->after->equals(new NodeRef(NodeType::Row, 16)));
    }

    public function testParseReorderBodyNullAfter(): void
    {
        $result = $this->parser->parseReorderBody([
            'element' => ['type' => 'section', 'id' => 1],
            'parent' => ['type' => 'page', 'id' => 1],
            'after' => null,
        ]);

        self::assertTrue($result->isOk());
        $request = $result->unwrap();
        self::assertNull($request->after);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('reorderBodyErrorProvider')]
    public function testParseReorderBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseReorderBody($input);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function reorderBodyErrorProvider(): iterable
    {
        $validElement = ['type' => 'row', 'id' => 17];
        $validParent = ['type' => 'section', 'id' => 1];

        yield 'missing element' => [
            ['parent' => $validParent],
            'element: ',
        ];

        yield 'element with zero id' => [
            ['element' => ['type' => 'row', 'id' => 0], 'parent' => $validParent],
            'element: ',
        ];

        yield 'element type is page' => [
            ['element' => ['type' => 'page', 'id' => 1], 'parent' => $validParent],
            'element type cannot be "page"',
        ];

        yield 'missing parent' => [
            ['element' => $validElement],
            'parent: ',
        ];

        yield 'parent with unknown type' => [
            ['element' => $validElement, 'parent' => ['type' => 'blob', 'id' => 1]],
            'parent: ',
        ];

        yield 'after with mismatched type' => [
            ['element' => $validElement, 'parent' => $validParent, 'after' => ['type' => 'column', 'id' => 16]],
            'after.type must match element.type',
        ];

        yield 'after with zero id' => [
            ['element' => $validElement, 'parent' => $validParent, 'after' => ['type' => 'row', 'id' => 0]],
            'after: ',
        ];
    }

    public function testParseUpdateGridSettingsBodyValid(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'element' => ['type' => 'column', 'id' => 1],
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(UpdateGridSettingsRequest::class, $request);
        self::assertTrue($request->element->equals(new NodeRef(NodeType::Column, 1)));
        self::assertSame('md', $request->viewport);
        self::assertSame(6, $request->width);
        self::assertSame(0, $request->offset);
        self::assertTrue($request->visible);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('updateGridSettingsBodyErrorProvider')]
    public function testParseUpdateGridSettingsBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody($input);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function updateGridSettingsBodyErrorProvider(): iterable
    {
        $valid = [
            'element' => ['type' => 'column', 'id' => 1],
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ];

        yield 'missing element' => [
            array_diff_key($valid, ['element' => null]),
            'element: ',
        ];

        yield 'element with zero id' => [
            array_merge($valid, ['element' => ['type' => 'column', 'id' => 0]]),
            'element: ',
        ];

        yield 'invalid viewport' => [
            array_merge($valid, ['viewport' => 'xxl']),
            'viewport is not a valid viewport key.',
        ];

        yield 'non-int width' => [
            array_merge($valid, ['width' => 'six']),
            'width must be an integer.',
        ];

        yield 'non-int offset' => [
            array_merge($valid, ['offset' => 'two']),
            'offset must be an integer.',
        ];

        yield 'non-bool visible' => [
            array_merge($valid, ['visible' => 1]),
            'visible must be a boolean.',
        ];
    }

    #[DataProvider('invalidWidthOffsetProvider')]
    public function testParseUpdateGridSettingsRejectsOutOfRangeWidthOffset(
        int $width,
        int $offset,
        string $expectedMessageFragment,
    ): void {
        $data = ['element' => ['type' => 'column', 'id' => 1], 'viewport' => 'md', 'width' => $width, 'offset' => $offset, 'visible' => true];
        $result = $this->parser->parseUpdateGridSettingsBody($data);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessageFragment, $result->errors()[0]->translate());
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function invalidWidthOffsetProvider(): iterable
    {
        yield 'zero width'      => [0, 0, 'width must be at least 1'];
        yield 'negative width'  => [-1, 0, 'width must be at least 1'];
        yield 'negative offset' => [1, -1, 'offset must not be negative'];
    }

    public function testParseUpdateGridSettingsAcceptsBoundaryWidthOffset(): void
    {
        $data = ['element' => ['type' => 'column', 'id' => 1], 'viewport' => 'md', 'width' => 1, 'offset' => 0, 'visible' => true];
        self::assertTrue($this->parser->parseUpdateGridSettingsBody($data)->isOk());
    }

    public function testParseDuplicateToBodyValid(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'element' => ['type' => 'section', 'id' => 1],
            'targetPageId' => 1,
            'targetZone' => 'sidebar',
            'targetParent' => ['type' => 'page', 'id' => 1],
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(DuplicateToRequest::class, $request);
        self::assertTrue($request->element->equals(new NodeRef(NodeType::Section, 1)));
        self::assertSame(1, $request->targetPageId);
        self::assertSame('sidebar', $request->targetZone);
        self::assertTrue($request->targetParent->equals(new NodeRef(NodeType::Page, 1)));
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('duplicateToBodyErrorProvider')]
    public function testParseDuplicateToBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseDuplicateToBody($input);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function duplicateToBodyErrorProvider(): iterable
    {
        $validElement = ['type' => 'row', 'id' => 5];
        $validTargetParent = ['type' => 'column', 'id' => 15];
        $valid = [
            'element' => $validElement,
            'targetPageId' => 10,
            'targetZone' => 'main',
            'targetParent' => $validTargetParent,
        ];

        yield 'missing element' => [
            ['targetPageId' => 10, 'targetZone' => 'main', 'targetParent' => $validTargetParent],
            'element: ',
        ];

        yield 'element with zero id' => [
            array_merge($valid, ['element' => ['type' => 'row', 'id' => 0]]),
            'element: ',
        ];

        yield 'element type is page' => [
            array_merge($valid, ['element' => ['type' => 'page', 'id' => 1]]),
            'element type cannot be "page"',
        ];

        yield 'non-int targetPageId' => [
            array_merge($valid, ['targetPageId' => 'abc']),
            'targetPageId must be a positive integer.',
        ];

        yield 'empty targetZone' => [
            array_merge($valid, ['targetZone' => '']),
            'targetZone must be a non-empty string.',
        ];

        yield 'missing targetParent' => [
            ['element' => $validElement, 'targetPageId' => 10, 'targetZone' => 'main'],
            'targetParent: ',
        ];

        yield 'targetParent with zero id' => [
            array_merge($valid, ['targetParent' => ['type' => 'column', 'id' => 0]]),
            'targetParent: ',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('resetGridSettingsOverridesValidProvider')]
    public function testParseResetGridSettingsOverridesBodyValid(
        array $input,
        int $expectedPageId,
        string $expectedZone,
        ?string $expectedViewport,
    ): void {
        $result = $this->parser->parseResetGridSettingsOverridesBody($input);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(ResetGridSettingsOverridesRequest::class, $request);
        self::assertSame($expectedPageId, $request->pageId);
        self::assertSame($expectedZone, $request->zone);
        self::assertSame($expectedViewport, $request->viewport);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string, ?string}>
     */
    public static function resetGridSettingsOverridesValidProvider(): iterable
    {
        yield 'with viewport' => [
            ['pageId' => 1, 'zone' => 'main', 'viewport' => 'md'],
            1, 'main', 'md',
        ];

        yield 'null viewport' => [
            ['pageId' => 1, 'zone' => 'main', 'viewport' => null],
            1, 'main', null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('resetGridSettingsOverridesErrorProvider')]
    public function testParseResetGridSettingsOverridesBodyRejectsInvalidInput(
        array $input,
        string $expectedMessage,
    ): void {
        $result = $this->parser->parseResetGridSettingsOverridesBody($input);

        self::assertTrue($result->isErr());
        self::assertSame($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function resetGridSettingsOverridesErrorProvider(): iterable
    {
        $valid = ['pageId' => 1, 'zone' => 'main', 'viewport' => 'md'];

        yield 'non-int pageId' => [
            array_merge($valid, ['pageId' => 'abc']),
            'pageId must be a positive integer.',
        ];

        yield 'zero pageId' => [
            array_merge($valid, ['pageId' => 0]),
            'pageId must be a positive integer.',
        ];

        yield 'non-string zone' => [
            array_merge($valid, ['zone' => 123]),
            'zone must be a non-empty string.',
        ];

        yield 'empty zone' => [
            array_merge($valid, ['zone' => '']),
            'zone must be a non-empty string.',
        ];

        yield 'non-string viewport' => [
            array_merge($valid, ['viewport' => 123]),
            'viewport must be a non-empty string or null.',
        ];

        yield 'invalid viewport' => [
            array_merge($valid, ['viewport' => 'xxl']),
            'viewport is not a valid viewport key.',
        ];

        yield 'default viewport rejected' => [
            array_merge($valid, ['viewport' => 'xs']),
            'Cannot reset the default viewport — it has no overrides.',
        ];
    }

    public function testParseElementRefValid(): void
    {
        $result = $this->parser->parseElementRef(['element' => ['type' => 'section', 'id' => 1]]);

        self::assertTrue($result->isOk());
        self::assertTrue($result->unwrap()->equals(new NodeRef(NodeType::Section, 1)));
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('elementRefErrorProvider')]
    public function testParseElementRefRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseElementRef($input);

        self::assertTrue($result->isErr());
        self::assertStringContainsString($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function elementRefErrorProvider(): iterable
    {
        yield 'missing element' => [[], 'element: '];
        yield 'element zero id' => [['element' => ['type' => 'section', 'id' => 0]], 'element: '];
        yield 'element non-int id' => [['element' => ['type' => 'section', 'id' => 'abc']], 'element: '];
        yield 'element unknown type' => [['element' => ['type' => 'foo', 'id' => 1]], 'element: '];
        yield 'element type page' => [
            ['element' => ['type' => 'page', 'id' => 1]],
            'element type cannot be "page"',
        ];
    }

    public function testParseElementRefFromQueryCoercesStringIdToNodeRef(): void
    {
        // Query values arrive as strings; the id must be coerced to an int.
        $result = $this->parser->parseElementRefFromQuery('section', '42');

        self::assertTrue($result->isOk());
        $ref = $result->unwrap();
        self::assertSame(NodeType::Section, $ref->type);
        self::assertSame(42, $ref->id);
    }

    public function testParseElementRefFromQueryRejectsNonNumericId(): void
    {
        $result = $this->parser->parseElementRefFromQuery('section', 'abc');

        self::assertTrue($result->isErr());
    }

    public function testParseElementRefFromQueryRejectsNonStringType(): void
    {
        $result = $this->parser->parseElementRefFromQuery(['not' => 'a string'], '42');

        self::assertTrue($result->isErr());
    }

    public function testParseResetOverridesFromQueryCoercesTypes(): void
    {
        // GridAdapterStub's default viewport is 'xs'; 'md' is a valid non-default.
        $result = $this->parser->parseResetGridSettingsOverridesFromQuery('7', 'main', 'md');

        self::assertTrue($result->isOk());
        $request = $result->unwrap();
        self::assertSame(7, $request->pageId);
        self::assertSame('main', $request->zone);
        self::assertSame('md', $request->viewport);
    }

    public function testParseResetOverridesFromQueryTreatsEmptyViewportAsNull(): void
    {
        // An absent viewport query var ('') means "reset all", not a viewport key.
        $result = $this->parser->parseResetGridSettingsOverridesFromQuery('7', 'main', '');

        self::assertTrue($result->isOk());
        self::assertNull($result->unwrap()->viewport);
    }

    public function testParseResetOverridesFromQueryRejectsNonNumericPageId(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesFromQuery('abc', 'main', null);

        self::assertTrue($result->isErr());
    }

    public function testParsePlaceSharedBlockBodyDefaultsInsertAtStartToFalse(): void
    {
        $result = $this->parser->parsePlaceSharedBlockBody([
            'blockId' => 4,
            'parent' => ['type' => 'page', 'id' => 2],
            'zone' => 'main',
        ]);

        self::assertTrue($result->isOk());
        self::assertFalse($result->unwrap()->insertAtStart);
    }

    public function testParsePlaceSharedBlockBodyAcceptsInsertAtStart(): void
    {
        $result = $this->parser->parsePlaceSharedBlockBody([
            'blockId' => 4,
            'parent' => ['type' => 'page', 'id' => 2],
            'zone' => 'main',
            'insertAtStart' => true,
        ]);

        self::assertTrue($result->isOk());
        self::assertTrue($result->unwrap()->insertAtStart);
        self::assertNull($result->unwrap()->insertAfterElementID);
    }

    public function testParsePlaceSharedBlockBodyRejectsNonBoolInsertAtStart(): void
    {
        $result = $this->parser->parsePlaceSharedBlockBody([
            'blockId' => 4,
            'parent' => ['type' => 'page', 'id' => 2],
            'insertAtStart' => 'yes',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('insertAtStart must be a boolean.', $result->errors()[0]->message);
    }

    public function testParsePlaceSharedBlockBodyRejectsInsertAtStartWithAfterElementId(): void
    {
        $result = $this->parser->parsePlaceSharedBlockBody([
            'blockId' => 4,
            'parent' => ['type' => 'page', 'id' => 2],
            'insertAfterElementID' => 9,
            'insertAtStart' => true,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame(
            'insertAtStart and insertAfterElementID are mutually exclusive.',
            $result->errors()[0]->message,
        );
    }
}
