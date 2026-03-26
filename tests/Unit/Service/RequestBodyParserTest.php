<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Service\RequestBodyParser;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\CreateContentRequest;
use WeDevelop\Grid\Value\CreateElementRequest;
use WeDevelop\Grid\Value\DuplicateToRequest;
use WeDevelop\Grid\Value\ReorderRequest;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ResetGridSettingsOverridesRequest;
use WeDevelop\Grid\Value\UpdateGridSettingsRequest;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(RequestBodyParser::class)]
#[CoversClass(CreateContentRequest::class)]
#[CoversClass(CreateElementRequest::class)]
#[CoversClass(DuplicateToRequest::class)]
#[CoversClass(ReorderRequest::class)]
#[CoversClass(Result::class)]
#[CoversClass(ResetGridSettingsOverridesRequest::class)]
#[CoversClass(UpdateGridSettingsRequest::class)]
#[CoversClass(ValidationError::class)]
#[CoversClass(Viewport::class)]
final class RequestBodyParserTest extends TestCase
{
    private GridAdapterInterface&MockObject $adapter;

    private RequestBodyParser $parser;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(GridAdapterInterface::class);
        $this->adapter->method('getViewports')->willReturn([
            new Viewport('sm', 'Small'),
            new Viewport('md', 'Medium'),
            new Viewport('lg', 'Large'),
        ]);
        $this->adapter->method('getDefaultViewport')->willReturn(new Viewport('md', 'Medium'));
        $this->adapter->method('getColumnCount')->willReturn(12);

        $this->parser = new RequestBodyParser($this->adapter);
    }

    // ---- parseCreateBody: success -------------------------------------------

    public function testParseCreateBodyReturnsValueObjectOnValidInput(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 5,
            'insertAfterElementID' => 3,
            'zone' => 'sidebar',
        ]);

        $this->assertTrue($result->isOk());

        $body = $result->unwrap();
        $this->assertInstanceOf(CreateElementRequest::class, $body);
        $this->assertSame(ContainerType::Section, $body->containerType);
        $this->assertSame(5, $body->parentId);
        $this->assertSame(3, $body->insertAfterElementID);
        $this->assertSame('sidebar', $body->zone);
    }

    public function testParseCreateBodyDefaultsZoneToMain(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'row',
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame('main', $result->unwrap()->zone);
    }

    public function testParseCreateBodyAcceptsNullAfterElementId(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrap()->insertAfterElementID);
    }

    // ---- parseCreateBody: failures ------------------------------------------

    public function testParseCreateBodyRejectsInvalidContainerType(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'invalid',
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsMissingContainerType(): void
    {
        $result = $this->parser->parseCreateBody([
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsFloatParentId(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 5.5,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsZeroParentId(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 0,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsNegativeParentId(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => -1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsZeroAfterElementId(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => 0,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsFloatAfterElementId(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => 5.5,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsEmptyZone(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => null,
            'zone' => '',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsNonStringZone(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => null,
            'zone' => 123,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsBooleanZone(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => null,
            'zone' => false,
        ]);

        $this->assertTrue($result->isErr());
    }

    // ---- parseCreateContentBody: success ------------------------------------

    public function testParseCreateContentBodyReturnsValueObject(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 7,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());

        $body = $result->unwrap();
        $this->assertInstanceOf(CreateContentRequest::class, $body);
        $this->assertSame(ContentElement::class, $body->className);
        $this->assertSame(7, $body->parentId);
        $this->assertNull($body->insertAfterElementID);
    }

    // ---- parseCreateContentBody: failures -----------------------------------

    public function testParseCreateContentBodyRejectsNonexistentClass(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => 'NonExistent\\FakeClass',
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateContentBodyRejectsNonContentElementClass(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => \stdClass::class,
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateContentBodyRejectsFloatParentId(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 1.5,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateContentBodyRejectsNonStringClassName(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => 123,
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    // ---- parseReorderBody: success ------------------------------------------

    public function testParseReorderBodyReturnsValueObject(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 10,
            'targetParentId' => 20,
            'afterElementID' => 5,
        ]);

        $this->assertTrue($result->isOk());

        $body = $result->unwrap();
        $this->assertInstanceOf(ReorderRequest::class, $body);
        $this->assertSame(10, $body->elementID);
        $this->assertSame(20, $body->targetParentId);
        $this->assertSame(5, $body->afterElementID);
    }

    public function testParseReorderBodyAcceptsNullAfterElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 10,
            'targetParentId' => 20,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrap()->afterElementID);
    }

    // ---- parseReorderBody: failures -----------------------------------------

    public function testParseReorderBodyRejectsZeroElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 0,
            'targetParentId' => 1,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsStringElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 'not-an-int',
            'targetParentId' => 1,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsNegativeElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => -5,
            'targetParentId' => 1,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsStringTargetParentId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 1,
            'targetParentId' => 'bad',
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsZeroTargetParentId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 1,
            'targetParentId' => 0,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsFloatAfterElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 1,
            'targetParentId' => 1,
            'afterElementID' => 5.5,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsStringAfterElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 1,
            'targetParentId' => 1,
            'afterElementID' => 'invalid',
        ]);

        $this->assertTrue($result->isErr());
    }

    // ---- parseUpdateGridSettingsBody: success --------------------------------

    public function testParseUpdateGridSettingsBodyReturnsValueObject(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 42,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 3,
            'visible' => true,
        ]);

        $this->assertTrue($result->isOk());

        $body = $result->unwrap();
        $this->assertInstanceOf(UpdateGridSettingsRequest::class, $body);
        $this->assertSame(42, $body->id);
        $this->assertSame('md', $body->viewport);
        $this->assertSame(6, $body->width);
        $this->assertSame(3, $body->offset);
        $this->assertTrue($body->visible);
    }

    // ---- parseUpdateGridSettingsBody: failures --------------------------------

    public function testParseUpdateGridSettingsBodyRejectsInvalidViewport(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'nonexistent',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    // Range validation (width/offset bounds, width+offset overflow) is now
    // enforced by GridSettingsFieldValidator on the DBField, not the parser.

    public function testParseUpdateGridSettingsBodyRejectsNonBoolVisible(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => 'yes',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsFloatId(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1.5,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    // ---- parseElementId: success & failures ----------------------------------

    public function testParseElementIdReturnsPositiveInt(): void
    {
        $result = $this->parser->parseElementId(['id' => 42]);

        $this->assertTrue($result->isOk());
        $this->assertSame(42, $result->unwrap());
    }

    public function testParseElementIdRejectsZero(): void
    {
        $result = $this->parser->parseElementId(['id' => 0]);

        $this->assertTrue($result->isErr());
    }

    public function testParseElementIdRejectsFloat(): void
    {
        $result = $this->parser->parseElementId(['id' => 5.5]);

        $this->assertTrue($result->isErr());
    }

    public function testParseElementIdRejectsMissingKey(): void
    {
        $result = $this->parser->parseElementId([]);

        $this->assertTrue($result->isErr());
    }

    public function testParseElementIdRejectsStringValue(): void
    {
        $result = $this->parser->parseElementId(['id' => 'abc']);

        $this->assertTrue($result->isErr());
    }

    // ---- parseDuplicateToBody: success --------------------------------------

    public function testParseDuplicateToBodyReturnsValueObject(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 10,
            'targetPageId' => 20,
            'targetZone' => 'main',
            'targetParentId' => 30,
        ]);

        $this->assertTrue($result->isOk());

        $body = $result->unwrap();
        $this->assertInstanceOf(DuplicateToRequest::class, $body);
        $this->assertSame(10, $body->id);
        $this->assertSame(20, $body->targetPageId);
        $this->assertSame('main', $body->targetZone);
        $this->assertSame(30, $body->targetParentId);
    }

    // ---- parseDuplicateToBody: failures -------------------------------------

    public function testParseDuplicateToBodyRejectsZeroId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 0,
            'targetPageId' => 1,
            'targetZone' => 'main',
            'targetParentId' => 1,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseDuplicateToBodyRejectsEmptyZone(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 1,
            'targetPageId' => 1,
            'targetZone' => '',
            'targetParentId' => 1,
        ]);

        $this->assertTrue($result->isErr());
    }

    // ---- Boundary value: 1 is accepted as a positive integer ----------------

    public function testParseCreateBodyAcceptsParentIdOfOne(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->parentId);
    }

    public function testParseCreateBodyAcceptsAfterElementIdOfOne(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 5,
            'insertAfterElementID' => 1,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->insertAfterElementID);
    }

    public function testParseCreateContentBodyAcceptsParentIdOfOne(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 1,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->parentId);
    }

    public function testParseCreateContentBodyAcceptsAfterElementIdOfOne(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 5,
            'insertAfterElementID' => 1,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->insertAfterElementID);
    }

    public function testParseReorderBodyAcceptsElementIdOfOne(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 1,
            'targetParentId' => 5,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->elementID);
    }

    public function testParseReorderBodyAcceptsTargetParentIdOfOne(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 5,
            'targetParentId' => 1,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->targetParentId);
    }

    public function testParseReorderBodyAcceptsAfterElementIdOfOne(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 5,
            'targetParentId' => 10,
            'afterElementID' => 1,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->afterElementID);
    }

    public function testParseUpdateGridSettingsBodyAcceptsIdOfOne(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->id);
    }

    public function testParseDuplicateToBodyAcceptsIdOfOne(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 1,
            'targetPageId' => 5,
            'targetZone' => 'main',
            'targetParentId' => 10,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->id);
    }

    public function testParseDuplicateToBodyAcceptsTargetPageIdOfOne(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => 1,
            'targetZone' => 'main',
            'targetParentId' => 10,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->targetPageId);
    }

    public function testParseDuplicateToBodyAcceptsTargetParentIdOfOne(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => 10,
            'targetZone' => 'main',
            'targetParentId' => 1,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap()->targetParentId);
    }

    public function testParseElementIdAcceptsIdOfOne(): void
    {
        $result = $this->parser->parseElementId(['id' => 1]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->unwrap());
    }

    // ---- Type coercion boundaries -------------------------------------------

    public function testParseElementIdRejectsStringThatLooksLikeInt(): void
    {
        $result = $this->parser->parseElementId(['id' => '42']);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateBodyRejectsWholeNumberFloat(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => 5.0,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsNumericStringElementId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => '10',
            'targetParentId' => 5,
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseReorderBodyRejectsNumericStringTargetParentId(): void
    {
        $result = $this->parser->parseReorderBody([
            'elementID' => 10,
            'targetParentId' => '5',
            'afterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsStringId(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => '1',
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsStringWidth(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => '6',
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsTruthyVisibleString(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => 0,
            'visible' => 'true',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseDuplicateToBodyRejectsStringTargetPageId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => '10',
            'targetZone' => 'main',
            'targetParentId' => 1,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseCreateContentBodyRejectsWholeNumberFloat(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 42.0,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isErr());
    }

    // ---- PHP_INT_MAX boundary -----------------------------------------------

    public function testParseElementIdAcceptsPhpIntMax(): void
    {
        $result = $this->parser->parseElementId(['id' => PHP_INT_MAX]);

        $this->assertTrue($result->isOk());
        $this->assertSame(PHP_INT_MAX, $result->unwrap());
    }

    public function testParseCreateBodyAcceptsPhpIntMaxParentId(): void
    {
        $result = $this->parser->parseCreateBody([
            'containerType' => 'section',
            'parentId' => PHP_INT_MAX,
            'insertAfterElementID' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertSame(PHP_INT_MAX, $result->unwrap()->parentId);
    }

    // ---- Missing rejection paths --------------------------------------------

    public function testParseCreateContentBodyRejectsNonPositiveAfterElementId(): void
    {
        $result = $this->parser->parseCreateContentBody([
            'className' => ContentElement::class,
            'parentId' => 1,
            'insertAfterElementID' => 0,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsNonStringViewport(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 123,
            'width' => 6,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsNonIntegerOffset(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => '0',
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseDuplicateToBodyRejectsNonPositiveTargetParentId(): void
    {
        $result = $this->parser->parseDuplicateToBody([
            'id' => 5,
            'targetPageId' => 10,
            'targetZone' => 'main',
            'targetParentId' => 0,
        ]);

        $this->assertTrue($result->isErr());
    }

    // ---- parseResetGridSettingsOverridesBody: success ------------------------

    public function testParseResetGridSettingsOverridesBodyReturnsValueObjectWithViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => 'main',
            'viewport' => 'sm',
        ]);

        $this->assertTrue($result->isOk());

        $body = $result->unwrap();
        $this->assertInstanceOf(ResetGridSettingsOverridesRequest::class, $body);
        $this->assertSame(5, $body->pageId);
        $this->assertSame('main', $body->zone);
        $this->assertSame('sm', $body->viewport);
    }

    public function testParseResetGridSettingsOverridesBodyReturnsValueObjectWithoutViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => 'main',
        ]);

        $this->assertTrue($result->isOk());

        $body = $result->unwrap();
        $this->assertInstanceOf(ResetGridSettingsOverridesRequest::class, $body);
        $this->assertSame(5, $body->pageId);
        $this->assertSame('main', $body->zone);
        $this->assertNull($body->viewport);
    }

    public function testParseResetGridSettingsOverridesBodyAcceptsNullViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => 'main',
            'viewport' => null,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrap()->viewport);
    }

    // ---- parseResetGridSettingsOverridesBody: failures -----------------------

    public function testParseResetGridSettingsOverridesBodyRejectsZeroPageId(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 0,
            'zone' => 'main',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseResetGridSettingsOverridesBodyRejectsStringPageId(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => '5',
            'zone' => 'main',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseResetGridSettingsOverridesBodyRejectsEmptyZone(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => '',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseResetGridSettingsOverridesBodyRejectsInvalidViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => 'main',
            'viewport' => 'nonexistent',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseResetGridSettingsOverridesBodyRejectsDefaultViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => 'main',
            'viewport' => 'md',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseResetGridSettingsOverridesBodyRejectsEmptyStringViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => 'main',
            'viewport' => '',
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseResetGridSettingsOverridesBodyRejectsIntegerViewport(): void
    {
        $result = $this->parser->parseResetGridSettingsOverridesBody([
            'pageId' => 5,
            'zone' => 'main',
            'viewport' => 123,
        ]);

        $this->assertTrue($result->isErr());
    }
}
