<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\UpdateGridSettingsRequest;

#[CoversClass(UpdateGridSettingsRequest::class)]
final class UpdateGridSettingsRequestTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $request = new UpdateGridSettingsRequest(
            id: 42,
            viewport: 'md',
            width: 6,
            offset: 2,
            visible: true,
        );

        $this->assertSame(42, $request->id);
        $this->assertSame('md', $request->viewport);
        $this->assertSame(6, $request->width);
        $this->assertSame(2, $request->offset);
        $this->assertTrue($request->visible);
    }

    public function testVisibleCanBeFalse(): void
    {
        $request = new UpdateGridSettingsRequest(
            id: 1,
            viewport: 'lg',
            width: 12,
            offset: 0,
            visible: false,
        );

        $this->assertFalse($request->visible);
    }
}
