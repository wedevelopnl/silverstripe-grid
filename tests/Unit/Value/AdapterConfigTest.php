<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\AdapterConfig;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(AdapterConfig::class)]
final class AdapterConfigTest extends TestCase
{
    public function testFromAdapterCapturesScalarConfiguration(): void
    {
        $config = AdapterConfig::fromAdapter(new GridAdapterStub());

        self::assertSame(12, $config->columnCount);
        self::assertSame('xs', $config->defaultViewport->key);
        self::assertSame('row', $config->rowClasses);
        self::assertSame(OffsetStrategy::Margin, $config->offsetStrategy);
        self::assertCount(3, $config->viewports);
    }

    public function testFromAdapterCapturesViewportObjects(): void
    {
        $config = AdapterConfig::fromAdapter(new GridAdapterStub());

        self::assertContainsOnlyInstancesOf(Viewport::class, $config->viewports);
        self::assertSame('xs', $config->viewports[0]->key);
        self::assertSame('Extra Small', $config->viewports[0]->label);
        self::assertSame(0, $config->viewports[0]->minWidth);
        self::assertSame('lg', $config->viewports[2]->key);
        self::assertSame(992, $config->viewports[2]->minWidth);
    }

    public function testFromAdapterBuildsWidthClassMapKeyedOneToColumnCount(): void
    {
        $config = AdapterConfig::fromAdapter(new GridAdapterStub());

        self::assertCount(12, $config->baseWidthClasses);
        self::assertArrayNotHasKey(0, $config->baseWidthClasses);
        self::assertSame('col-1', $config->baseWidthClasses[1]);
        self::assertSame('col-12', $config->baseWidthClasses[12]);
    }

    public function testFromAdapterBuildsOffsetClassMapKeyedZeroToColumnCountMinusOne(): void
    {
        $config = AdapterConfig::fromAdapter(new GridAdapterStub());

        self::assertCount(12, $config->baseOffsetClasses);
        self::assertSame('offset-0', $config->baseOffsetClasses[0]);
        self::assertSame('offset-11', $config->baseOffsetClasses[11]);
        self::assertArrayNotHasKey(12, $config->baseOffsetClasses);
    }

    public function testFromAdapterThrowsWhenAdapterHasNoViewports(): void
    {
        // An adapter that declares no viewports is a misconfiguration: the
        // frontend cannot render a grid without at least one breakpoint. A
        // defaultViewport must be supplied because the stub otherwise falls
        // back to viewports[0], which is undefined for an empty set.
        $adapter = new GridAdapterStub(
            viewports: [],
            defaultViewport: new Viewport('xs', 'Extra Small', 0),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Adapter must define at least one viewport.');

        AdapterConfig::fromAdapter($adapter);
    }

    public function testJsonSerializeProducesFrontendWireShape(): void
    {
        $serialized = AdapterConfig::fromAdapter(new GridAdapterStub())->jsonSerialize();

        self::assertSame(
            [
                ['key' => 'xs', 'label' => 'Extra Small', 'minWidth' => 0],
                ['key' => 'md', 'label' => 'Medium', 'minWidth' => 768],
                ['key' => 'lg', 'label' => 'Large', 'minWidth' => 992],
            ],
            $serialized['viewports'],
        );
        self::assertSame('xs', $serialized['defaultViewport']);
        self::assertSame(12, $serialized['columnCount']);
        self::assertSame('row', $serialized['rowClasses']);
        self::assertSame('margin', $serialized['offsetStrategy']);
        self::assertInstanceOf(stdClass::class, $serialized['baseWidthClasses']);
        self::assertInstanceOf(stdClass::class, $serialized['baseOffsetClasses']);
    }

    public function testJsonSerializeEncodesOffsetMapAsJsonObjectNotArray(): void
    {
        // baseOffsetClasses keys are 0..11 — sequential and zero-based, so a
        // plain PHP array would json_encode to a JSON *array*. The frontend
        // contract (AdapterConfig.baseOffsetClasses: Record<string, string>)
        // requires a JSON object, so the value must serialize as one.
        $json = json_encode(AdapterConfig::fromAdapter(new GridAdapterStub()));

        self::assertIsString($json);
        self::assertStringContainsString('"baseOffsetClasses":{"0":"offset-0"', $json);
    }
}
