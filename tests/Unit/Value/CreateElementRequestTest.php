<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\CreateElementRequest;

#[CoversClass(CreateElementRequest::class)]
final class CreateElementRequestTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $request = new CreateElementRequest(
            containerType: ContainerType::Row,
            parentId: 10,
            insertAfterElementID: 5,
            zone: 'main',
        );

        $this->assertSame(ContainerType::Row, $request->containerType);
        $this->assertSame(10, $request->parentId);
        $this->assertSame(5, $request->insertAfterElementID);
        $this->assertSame('main', $request->zone);
    }

    public function testInsertAfterElementIDCanBeNull(): void
    {
        $request = new CreateElementRequest(
            containerType: ContainerType::Section,
            parentId: 1,
            insertAfterElementID: null,
            zone: 'sidebar',
        );

        $this->assertNull($request->insertAfterElementID);
    }
}
