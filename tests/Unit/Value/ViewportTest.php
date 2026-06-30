<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(Viewport::class)]
final class ViewportTest extends TestCase
{
    public function testJsonSerializeExposesKeyLabelAndMinWidth(): void
    {
        $viewport = new Viewport('md', 'Medium', 768);

        self::assertSame(
            ['key' => 'md', 'label' => 'Medium', 'minWidth' => 768],
            $viewport->jsonSerialize(),
        );
    }
}
