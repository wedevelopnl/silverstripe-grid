<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\AdapterConfig;
use WeDevelop\Grid\Value\Viewport;

#[CoversClass(AdapterConfig::class)]
final class AdapterConfigTest extends TestCase
{
    private AdapterConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = AdapterConfig::fromAdapter(new GridAdapterStub());
    }

    public function testFromAdapterKeysWidthClassMapFromOneToColumnCount(): void
    {
        // Width map is keyed by column span (1..columnCount) so the frontend can
        // index by span. Asserting the key set — not the stub's sprintf output —
        // pins AdapterConfig's own logic; a non-default column count proves the
        // loop honours the adapter's count and is not hardcoded to 12 (projects
        // override total_columns, e.g. to 16). Real class values are covered
        // against a live preset in the integration-tier AdapterConfigTest.
        $config = AdapterConfig::fromAdapter(new GridAdapterStub(columnCount: 4));

        self::assertSame([1, 2, 3, 4], array_keys($config->baseWidthClasses));
    }

    public function testFromAdapterKeysOffsetClassMapFromZeroToColumnCountMinusOne(): void
    {
        // Offsets are 0-based and stop one short of the column count (a full-width
        // offset is meaningless), so the map is keyed 0..columnCount-1.
        $config = AdapterConfig::fromAdapter(new GridAdapterStub(columnCount: 4));

        self::assertSame([0, 1, 2, 3], array_keys($config->baseOffsetClasses));
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
        $serialized = $this->config->jsonSerialize();

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
        $json = json_encode($this->config);

        self::assertIsString($json);
        self::assertStringContainsString('"baseOffsetClasses":{"0":"offset-0"', $json);
    }
}
