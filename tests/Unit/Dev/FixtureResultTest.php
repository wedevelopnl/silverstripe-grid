<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Dev\FixtureResult;

#[CoversClass(FixtureResult::class)]
final class FixtureResultTest extends TestCase
{
    public function testJsonSerializeReturnsExpectedStructure(): void
    {
        $result = new FixtureResult(
            fixtureName: 'element-tree',
            pageId: 42,
            pageUrl: '/e2e-grid-test/',
            fixtureMap: ['Page' => ['e2e_page' => 42]],
        );

        $json = $result->jsonSerialize();

        self::assertArrayHasKey('pageId', $json);
        self::assertArrayHasKey('pageUrl', $json);
        self::assertArrayHasKey('fixtureMap', $json);
        self::assertSame(42, $json['pageId']);
        self::assertSame('/e2e-grid-test/', $json['pageUrl']);
    }

    public function testJsonSerializeOmitsFixtureName(): void
    {
        $result = new FixtureResult(
            fixtureName: 'element-tree',
            pageId: 1,
            pageUrl: '/',
            fixtureMap: [],
        );

        $json = $result->jsonSerialize();

        self::assertArrayNotHasKey('fixtureName', $json);
    }
}
