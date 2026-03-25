<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(ViewportConfig::class)]
final class ViewportConfigTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $config = new ViewportConfig(6, 2, true);

        $this->assertSame(6, $config->width);
        $this->assertSame(2, $config->offset);
        $this->assertTrue($config->visible);
    }

    public function testDefaultFactory(): void
    {
        $config = ViewportConfig::default(12);

        $this->assertSame(12, $config->width);
        $this->assertSame(0, $config->offset);
        $this->assertTrue($config->visible);
    }

    public function testFromArray(): void
    {
        $config = ViewportConfig::fromArray([
            'width' => 8,
            'offset' => 1,
            'visible' => false,
        ]);

        $this->assertSame(8, $config->width);
        $this->assertSame(1, $config->offset);
        $this->assertFalse($config->visible);
    }

    public function testToArray(): void
    {
        $config = new ViewportConfig(6, 3, true);

        $this->assertSame([
            'width' => 6,
            'offset' => 3,
            'visible' => true,
        ], $config->toArray());
    }

    public function testEqualsReturnsTrueForIdenticalValues(): void
    {
        $a = new ViewportConfig(6, 2, true);
        $b = new ViewportConfig(6, 2, true);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentWidth(): void
    {
        $a = new ViewportConfig(6, 2, true);
        $b = new ViewportConfig(8, 2, true);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentOffset(): void
    {
        $a = new ViewportConfig(6, 2, true);
        $b = new ViewportConfig(6, 0, true);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentVisibility(): void
    {
        $a = new ViewportConfig(6, 2, true);
        $b = new ViewportConfig(6, 2, false);

        $this->assertFalse($a->equals($b));
    }

    public function testFromArrayRoundTrips(): void
    {
        $original = new ViewportConfig(4, 1, false);
        $restored = ViewportConfig::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }
}
