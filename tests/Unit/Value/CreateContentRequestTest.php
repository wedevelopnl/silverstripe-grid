<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Value\CreateContentRequest;

#[CoversClass(CreateContentRequest::class)]
final class CreateContentRequestTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $request = new CreateContentRequest(
            className: ContentElement::class,
            parentId: 42,
            insertAfterElementID: 7,
        );

        $this->assertSame(ContentElement::class, $request->className);
        $this->assertSame(42, $request->parentId);
        $this->assertSame(7, $request->insertAfterElementID);
    }

    public function testInsertAfterElementIDCanBeNull(): void
    {
        $request = new CreateContentRequest(
            className: ContentElement::class,
            parentId: 1,
            insertAfterElementID: null,
        );

        $this->assertNull($request->insertAfterElementID);
    }
}
