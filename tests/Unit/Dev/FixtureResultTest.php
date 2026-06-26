<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Dev\FixtureResult;

#[CoversClass(FixtureResult::class)]
final class FixtureResultTest extends TestCase
{
    public function testJsonSerializeReturnsFullShapeWithoutFixtureName(): void
    {
        $result = new FixtureResult(
            fixtureName: 'element-tree',
            pageId: 42,
            pageUrl: '/e2e-grid-test/',
            fixtureMap: ['Page' => ['e2e_page' => 42]],
        );

        self::assertSame(
            [
                'pageId' => 42,
                'pageUrl' => '/e2e-grid-test/',
                'fixtureMap' => ['Page' => ['e2e_page' => 42]],
            ],
            $result->jsonSerialize(),
        );
    }
}
