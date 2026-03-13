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
use WeDevelop\Grid\Value\ReorderRequest;
use WeDevelop\Grid\Value\UpdateGridSettingsRequest;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(RequestBodyParser::class)]
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

    public function testParseUpdateGridSettingsBodyRejectsWidthExceedingColumnCount(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 13,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsZeroWidth(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 0,
            'offset' => 0,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsNegativeOffset(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 6,
            'offset' => -1,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
    }

    public function testParseUpdateGridSettingsBodyRejectsWidthPlusOffsetOverflow(): void
    {
        $result = $this->parser->parseUpdateGridSettingsBody([
            'id' => 1,
            'viewport' => 'md',
            'width' => 8,
            'offset' => 5,
            'visible' => true,
        ]);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('exceeds', $result->errors()[0]->message);
    }

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
}
