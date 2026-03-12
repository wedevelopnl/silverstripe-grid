<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Dev\FixtureResult;

#[CoversClass(FixtureResult::class)]
final class FixtureResultTest extends TestCase
{
    public function testConstructorStoresAllProperties(): void
    {
        $fixtureMap = ['SomePage' => ['page1' => 1]];

        $result = new FixtureResult(
            fixtureName: 'test-fixture',
            pageId: 42,
            pageUrl: '/test-page/',
            fixtureMap: $fixtureMap,
        );

        $this->assertSame('test-fixture', $result->fixtureName);
        $this->assertSame(42, $result->pageId);
        $this->assertSame('/test-page/', $result->pageUrl);
        $this->assertSame($fixtureMap, $result->fixtureMap);
    }

    public function testJsonSerializeExcludesFixtureName(): void
    {
        $result = new FixtureResult(
            fixtureName: 'should-not-appear',
            pageId: 7,
            pageUrl: '/page/',
            fixtureMap: [],
        );

        $serialized = $result->jsonSerialize();

        $this->assertArrayNotHasKey('fixtureName', $serialized);
    }

    public function testJsonSerializeReturnsExpectedShape(): void
    {
        $fixtureMap = [
            'PageClass' => ['home' => 10, 'about' => 20],
            'ElementClass' => ['section1' => 100],
        ];

        $result = new FixtureResult(
            fixtureName: 'complex',
            pageId: 10,
            pageUrl: '/home/',
            fixtureMap: $fixtureMap,
        );

        $this->assertSame(
            [
                'pageId' => 10,
                'pageUrl' => '/home/',
                'fixtureMap' => $fixtureMap,
            ],
            $result->jsonSerialize(),
        );
    }

    public function testJsonEncodeProducesValidJson(): void
    {
        $result = new FixtureResult(
            fixtureName: 'encode-test',
            pageId: 99,
            pageUrl: '/encode/',
            fixtureMap: ['Class' => ['id' => 5]],
        );

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(99, $decoded['pageId']);
        $this->assertSame('/encode/', $decoded['pageUrl']);
        $this->assertSame(['Class' => ['id' => 5]], $decoded['fixtureMap']);
        $this->assertArrayNotHasKey('fixtureName', $decoded);
    }

    public function testJsonSerializeWithEmptyFixtureMap(): void
    {
        $result = new FixtureResult(
            fixtureName: 'empty',
            pageId: 1,
            pageUrl: '/',
            fixtureMap: [],
        );

        $serialized = $result->jsonSerialize();

        $this->assertSame([], $serialized['fixtureMap']);
    }
}
