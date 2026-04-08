<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Service\RequestBodyParser;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\CreateContentRequest;
use WeDevelop\Grid\Value\CreateElementRequest;
use WeDevelop\Grid\Value\DuplicateToRequest;
use WeDevelop\Grid\Value\ReorderRequest;
use WeDevelop\Grid\Value\ResetGridSettingsOverridesRequest;
use WeDevelop\Grid\Value\UpdateGridSettingsRequest;

#[CoversClass(RequestBodyParser::class)]
#[CoversClass(CreateContentRequest::class)]
#[CoversClass(CreateElementRequest::class)]
#[CoversClass(DuplicateToRequest::class)]
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

    // ── parseCreateBody ─────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('createBodyValidProvider')]
    public function testParseCreateBodyValid(
        array $input,
        ContainerType $expectedType,
        int $expectedParentId,
        ?int $expectedInsertAfter,
        string $expectedZone,
    ): void {
        $result = $this->parser->parseCreateBody($input);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(CreateElementRequest::class, $request);
        self::assertSame($expectedType, $request->containerType);
        self::assertSame($expectedParentId, $request->parentId);
        self::assertSame($expectedInsertAfter, $request->insertAfterElementID);
        self::assertSame($expectedZone, $request->zone);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ContainerType, int, ?int, string}>
     */
    public static function createBodyValidProvider(): iterable
    {
        yield 'all fields' => [
            ['containerType' => 'section', 'parentId' => 1, 'insertAfterElementID' => 5, 'zone' => 'sidebar'],
            ContainerType::Section, 1, 5, 'sidebar',
        ];

        yield 'null insertAfterElementID' => [
            ['containerType' => 'row', 'parentId' => 5, 'insertAfterElementID' => null],
            ContainerType::Row, 5, null, 'main',
        ];

        yield 'default zone' => [
            ['containerType' => 'column', 'parentId' => 3],
            ContainerType::Column, 3, null, 'main',
        ];

        yield 'insertAfterElementID = 1' => [
            ['containerType' => 'section', 'parentId' => 1, 'insertAfterElementID' => 1],
            ContainerType::Section, 1, 1, 'main',
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
        self::assertSame($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function createBodyErrorProvider(): iterable
    {
        yield 'missing containerType' => [
            ['parentId' => 1],
            'Invalid or missing containerType.',
        ];

        yield 'invalid containerType' => [
            ['containerType' => 'invalid', 'parentId' => 1],
            'Invalid or missing containerType.',
        ];

        yield 'non-string containerType' => [
            ['containerType' => 123, 'parentId' => 1],
            'Invalid or missing containerType.',
        ];

        yield 'non-int parentId' => [
            ['containerType' => 'section', 'parentId' => 'abc'],
            'parentId must be a positive integer.',
        ];

        yield 'zero parentId' => [
            ['containerType' => 'section', 'parentId' => 0],
            'parentId must be a positive integer.',
        ];

        yield 'non-int insertAfterElementID' => [
            ['containerType' => 'section', 'parentId' => 1, 'insertAfterElementID' => 'abc'],
            'insertAfterElementID must be a positive integer or null.',
        ];

        yield 'zero insertAfterElementID' => [
            ['containerType' => 'section', 'parentId' => 1, 'insertAfterElementID' => 0],
            'insertAfterElementID must be a positive integer or null.',
        ];

        yield 'empty zone' => [
            ['containerType' => 'section', 'parentId' => 1, 'zone' => ''],
            'zone must be a non-empty string.',
        ];
    }

    // ── parseCreateContentBody ──────────────────────────────────

    public function testParseCreateContentBodyValid(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 1,
            'insertAfterElementID' => 1,
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(CreateContentRequest::class, $request);
        self::assertSame(ContentElement::class, $request->className);
        self::assertSame(1, $request->parentId);
        self::assertSame(1, $request->insertAfterElementID);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('createContentBodyErrorProvider')]
    public function testParseCreateContentBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseCreateContentBody($input);

        self::assertTrue($result->isErr());
        self::assertSame($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function createContentBodyErrorProvider(): iterable
    {
        yield 'non-string className' => [
            ['className' => 123, 'parentId' => 1],
            'className must be a string.',
        ];

        yield 'non-existent class' => [
            ['className' => 'NonExistent\\Class', 'parentId' => 1],
            'className does not refer to an existing class.',
        ];

        yield 'not a ContentElement subclass' => [
            ['className' => stdClass::class, 'parentId' => 1],
            'className must be a ContentElement subclass.',
        ];

        yield 'non-int parentId' => [
            ['className' => ContentElement::class, 'parentId' => 'abc'],
            'parentId must be a positive integer.',
        ];

        yield 'zero parentId' => [
            ['className' => ContentElement::class, 'parentId' => 0],
            'parentId must be a positive integer.',
        ];

        yield 'non-int insertAfterElementID' => [
            ['className' => ContentElement::class, 'parentId' => 1, 'insertAfterElementID' => 'abc'],
            'insertAfterElementID must be a positive integer or null.',
        ];
    }

    // ── parseReorderBody ────────────────────────────────────────

    public function testParseReorderBodyValid(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 1,
            'targetParentId' => 1,
            'afterElementID' => 1,
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(ReorderRequest::class, $request);
        self::assertSame(1, $request->elementID);
        self::assertSame(1, $request->targetParentId);
        self::assertSame(1, $request->afterElementID);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('reorderBodyErrorProvider')]
    public function testParseReorderBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseReorderBody($input);

        self::assertTrue($result->isErr());
        self::assertSame($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function reorderBodyErrorProvider(): iterable
    {
        yield 'non-int elementID' => [
            ['elementID' => 'abc', 'targetParentId' => 20],
            'elementID must be a positive integer.',
        ];

        yield 'zero elementID' => [
            ['elementID' => 0, 'targetParentId' => 20],
            'elementID must be a positive integer.',
        ];

        yield 'non-int targetParentId' => [
            ['elementID' => 10, 'targetParentId' => 'abc'],
            'targetParentId must be a positive integer.',
        ];

        yield 'non-int afterElementID' => [
            ['elementID' => 10, 'targetParentId' => 20, 'afterElementID' => 'abc'],
            'afterElementID must be a positive integer or null.',
        ];

        yield 'zero afterElementID' => [
            ['elementID' => 10, 'targetParentId' => 20, 'afterElementID' => 0],
            'afterElementID must be a positive integer or null.',
        ];
    }

    // ── parseUpdateGridSettingsBody ─────────────────────────────

    public function testParseUpdateGridSettingsBodyValid(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(UpdateGridSettingsRequest::class, $request);
        self::assertSame(1, $request->id);
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
        self::assertSame($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function updateGridSettingsBodyErrorProvider(): iterable
    {
        $valid = ['id' => 1, 'viewport' => 'md', 'width' => 6, 'offset' => 0, 'visible' => true];

        yield 'non-int id' => [
            array_merge($valid, ['id' => 'abc']),
            'id must be a positive integer.',
        ];

        yield 'zero id' => [
            array_merge($valid, ['id' => 0]),
            'id must be a positive integer.',
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

    // ── parseDuplicateToBody ────────────────────────────────────

    public function testParseDuplicateToBodyValid(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 1,
            'targetPageId' => 1,
            'targetZone' => 'sidebar',
            'targetParentId' => 1,
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(DuplicateToRequest::class, $request);
        self::assertSame(1, $request->id);
        self::assertSame(1, $request->targetPageId);
        self::assertSame('sidebar', $request->targetZone);
        self::assertSame(1, $request->targetParentId);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('duplicateToBodyErrorProvider')]
    public function testParseDuplicateToBodyRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $result = $this->parser->parseDuplicateToBody($input);

        self::assertTrue($result->isErr());
        self::assertSame($expectedMessage, $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function duplicateToBodyErrorProvider(): iterable
    {
        $valid = ['id' => 5, 'targetPageId' => 10, 'targetZone' => 'main', 'targetParentId' => 15];

        yield 'non-int id' => [
            array_merge($valid, ['id' => 'abc']),
            'id must be a positive integer.',
        ];

        yield 'zero id' => [
            array_merge($valid, ['id' => 0]),
            'id must be a positive integer.',
        ];

        yield 'non-int targetPageId' => [
            array_merge($valid, ['targetPageId' => 'abc']),
            'targetPageId must be a positive integer.',
        ];

        yield 'empty targetZone' => [
            array_merge($valid, ['targetZone' => '']),
            'targetZone must be a non-empty string.',
        ];

        yield 'non-int targetParentId' => [
            array_merge($valid, ['targetParentId' => 'abc']),
            'targetParentId must be a positive integer.',
        ];

        yield 'zero targetParentId' => [
            array_merge($valid, ['targetParentId' => 0]),
            'targetParentId must be a positive integer.',
        ];
    }

    // ── parseResetGridSettingsOverridesBody ──────────────────────

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

    // ── parseElementId ──────────────────────────────────────────

    public function testParseElementIdValid(): void
    {
        $result = $this->parser->parseElementId(['id' => 1]);

        self::assertTrue($result->isOk());
        self::assertSame(1, $result->unwrap());
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('elementIdErrorProvider')]
    public function testParseElementIdRejectsInvalidInput(array $input): void
    {
        $result = $this->parser->parseElementId($input);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function elementIdErrorProvider(): iterable
    {
        yield 'non-int' => [['id' => 'abc']];
        yield 'zero' => [['id' => 0]];
        yield 'missing' => [[]];
    }
}
