<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
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
final class RequestBodyParserTest extends TestCase
{
    private RequestBodyParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RequestBodyParser(new GridAdapterStub());
    }

    // ── parseCreateBody ─────────────────────────────────────────

    public function testParseCreateBodyValid(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => 5,
            'zone' => 'sidebar',
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(CreateElementRequest::class, $request);
        self::assertSame(ContainerType::Section, $request->containerType);
        self::assertSame(1, $request->parentId);
        self::assertSame(5, $request->insertAfterElementID);
        self::assertSame('sidebar', $request->zone);
    }

    public function testParseCreateBodyMissingContainerType(): void
    {
        $result = $this->parser->parseCreateBody(['parentId' => 1]);

        self::assertTrue($result->isErr());
        self::assertSame('Invalid or missing containerType.', $result->errors()[0]->message);
    }

    public function testParseCreateBodyInvalidContainerType(): void
    {
        $result = $this->parser->parseCreateBody(['containerType' => 'invalid', 'parentId' => 1]);

        self::assertTrue($result->isErr());
        self::assertSame('Invalid or missing containerType.', $result->errors()[0]->message);
    }

    public function testParseCreateBodyNonIntParentId(): void
    {
        $result = $this->parser->parseCreateBody(['containerType' => 'section', 'parentId' => 'abc']);

        self::assertTrue($result->isErr());
        self::assertSame('parentId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseCreateBodyZeroParentId(): void
    {
        $result = $this->parser->parseCreateBody(['containerType' => 'section', 'parentId' => 0]);

        self::assertTrue($result->isErr());
        self::assertSame('parentId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseCreateBodyInsertAfterNonInt(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => 'abc',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('insertAfterElementID must be a positive integer or null.', $result->errors()[0]->message);
    }

    public function testParseCreateBodyInsertAfterZero(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => 0,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('insertAfterElementID must be a positive integer or null.', $result->errors()[0]->message);
    }

    public function testParseCreateBodyInsertAfterOneIsValid(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => 1,
        ]);

        self::assertTrue($result->isOk());
        self::assertSame(1, $result->unwrap()->insertAfterElementID);
    }

    public function testParseCreateBodyNullInsertAfterAllowed(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'row',
            'parentId' => 5,
            'insertAfterElementID' => null,
        ]);

        self::assertTrue($result->isOk());
        self::assertNull($result->unwrap()->insertAfterElementID);
    }

    public function testParseCreateBodyDefaultZone(): void
    {
        $result = $this->parser->parseCreateBody(['containerType' => 'column', 'parentId' => 3]);

        self::assertTrue($result->isOk());
        self::assertSame('main', $result->unwrap()->zone);
    }

    public function testParseCreateBodyEmptyZone(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'zone' => '',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('zone must be a non-empty string.', $result->errors()[0]->message);
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

    public function testParseCreateContentBodyNonStringClassName(): void
    {
        $result = $this->parser->parseCreateContentBody(['className' => 123, 'parentId' => 1]);

        self::assertTrue($result->isErr());
        self::assertSame('className must be a string.', $result->errors()[0]->message);
    }

    public function testParseCreateContentBodyNonExistentClass(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => 'NonExistent\\Class',
            'parentId' => 1,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('className does not refer to an existing class.', $result->errors()[0]->message);
    }

    public function testParseCreateContentBodyNotContentElement(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => stdClass::class,
            'parentId' => 1,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('className must be a ContentElement subclass.', $result->errors()[0]->message);
    }

    public function testParseCreateContentBodyNonIntParentId(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 'abc',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('parentId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseCreateContentBodyZeroParentId(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 0,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('parentId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseCreateContentBodyInsertAfterNonInt(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 1,
            'insertAfterElementID' => 'abc',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('insertAfterElementID must be a positive integer or null.', $result->errors()[0]->message);
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

    public function testParseReorderBodyNonIntElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 'abc',
            'targetParentId' => 20,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('elementID must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseReorderBodyZeroElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 0,
            'targetParentId' => 20,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('elementID must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseReorderBodyNonIntTargetParentId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 10,
            'targetParentId' => 'abc',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('targetParentId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseReorderBodyAfterElementIdNonInt(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 10,
            'targetParentId' => 20,
            'afterElementID' => 'abc',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('afterElementID must be a positive integer or null.', $result->errors()[0]->message);
    }

    public function testParseReorderBodyAfterElementIdZero(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 10,
            'targetParentId' => 20,
            'afterElementID' => 0,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('afterElementID must be a positive integer or null.', $result->errors()[0]->message);
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

    public function testParseUpdateGridSettingsBodyNonIntId(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 'abc',
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseUpdateGridSettingsBodyZeroId(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 0,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseUpdateGridSettingsBodyInvalidViewport(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'xxl',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('viewport is not a valid viewport key.', $result->errors()[0]->message);
    }

    public function testParseUpdateGridSettingsBodyNonIntWidth(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 'six',
            'offset' => 0,
            'visible' => true,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('width must be an integer.', $result->errors()[0]->message);
    }

    public function testParseUpdateGridSettingsBodyNonIntOffset(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 'two',
            'visible' => true,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('offset must be an integer.', $result->errors()[0]->message);
    }

    public function testParseUpdateGridSettingsBodyNonBoolVisible(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => 1,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('visible must be a boolean.', $result->errors()[0]->message);
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

    public function testParseDuplicateToBodyNonIntId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 'abc',
            'targetPageId' => 10,
            'targetZone' => 'main',
            'targetParentId' => 15,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseDuplicateToBodyZeroId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 0,
            'targetPageId' => 10,
            'targetZone' => 'main',
            'targetParentId' => 15,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseDuplicateToBodyNonIntTargetPageId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => 'abc',
            'targetZone' => 'main',
            'targetParentId' => 15,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('targetPageId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseDuplicateToBodyEmptyTargetZone(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => 10,
            'targetZone' => '',
            'targetParentId' => 15,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('targetZone must be a non-empty string.', $result->errors()[0]->message);
    }

    public function testParseDuplicateToBodyNonIntTargetParentId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => 10,
            'targetZone' => 'main',
            'targetParentId' => 'abc',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('targetParentId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseDuplicateToBodyZeroTargetParentId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => 10,
            'targetZone' => 'main',
            'targetParentId' => 0,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('targetParentId must be a positive integer.', $result->errors()[0]->message);
    }

    // ── parseResetGridSettingsOverridesBody ──────────────────────

    public function testParseResetValid(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 1,
            'zone' => 'main',
            'viewport' => 'md',
        ]);

        self::assertTrue($result->isOk());

        $request = $result->unwrap();
        self::assertInstanceOf(ResetGridSettingsOverridesRequest::class, $request);
        self::assertSame(1, $request->pageId);
        self::assertSame('main', $request->zone);
        self::assertSame('md', $request->viewport);
    }

    public function testParseResetNullViewportAllowed(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 1,
            'zone' => 'main',
            'viewport' => null,
        ]);

        self::assertTrue($result->isOk());
        self::assertNull($result->unwrap()->viewport);
    }

    public function testParseResetNonIntPageId(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 'abc',
            'zone' => 'main',
            'viewport' => 'md',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('pageId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseResetZeroPageId(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 0,
            'zone' => 'main',
            'viewport' => 'md',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('pageId must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseResetNonStringZone(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 1,
            'zone' => 123,
            'viewport' => 'md',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('zone must be a non-empty string.', $result->errors()[0]->message);
    }

    public function testParseResetEmptyZone(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 1,
            'zone' => '',
            'viewport' => 'md',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('zone must be a non-empty string.', $result->errors()[0]->message);
    }

    public function testParseResetNonStringViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 1,
            'zone' => 'main',
            'viewport' => 123,
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('viewport must be a non-empty string or null.', $result->errors()[0]->message);
    }

    public function testParseResetInvalidViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 1,
            'zone' => 'main',
            'viewport' => 'xxl',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('viewport is not a valid viewport key.', $result->errors()[0]->message);
    }

    public function testParseResetDefaultViewportRejected(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 1,
            'zone' => 'main',
            'viewport' => 'xs',
        ]);

        self::assertTrue($result->isErr());
        self::assertSame('Cannot reset the default viewport — it has no overrides.', $result->errors()[0]->message);
    }

    // ── parseElementId ──────────────────────────────────────────

    public function testParseElementIdValid(): void
    {
        $result = $this->parser->parseElementId(['id' => 1]);

        self::assertTrue($result->isOk());
        self::assertSame(1, $result->unwrap());
    }

    public function testParseElementIdNonInt(): void
    {
        $result = $this->parser->parseElementId(['id' => 'abc']);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseElementIdZero(): void
    {
        $result = $this->parser->parseElementId(['id' => 0]);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }

    public function testParseElementIdMissing(): void
    {
        $result = $this->parser->parseElementId([]);

        self::assertTrue($result->isErr());
        self::assertSame('id must be a positive integer.', $result->errors()[0]->message);
    }
}
