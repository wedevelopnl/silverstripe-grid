<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ReorderRequest;

#[CoversClass(ReorderRequest::class)]
final class ReorderRequestTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $request = new ReorderRequest(
            elementID: 42,
            targetParentId: 10,
            afterElementID: 7,
        );

        $this->assertSame(42, $request->elementID);
        $this->assertSame(10, $request->targetParentId);
        $this->assertSame(7, $request->afterElementID);
    }

    public function testAfterElementIDCanBeNull(): void
    {
        $request = new ReorderRequest(
            elementID: 1,
            targetParentId: 2,
            afterElementID: null,
        );

        $this->assertNull($request->afterElementID);
    }
}
