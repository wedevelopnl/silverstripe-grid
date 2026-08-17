<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Value\AdapterConfig;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Exercises {@see AdapterConfig::fromAdapter()} against Tailwind — the module's
 * default preset ({@see \WeDevelop\Grid\Factory\GridAdapterResolver}) — so the
 * asserted class names are a live adapter's actual CSS vocabulary rather than a
 * stub's synthetic sprintf. Tailwind's grid-placement offsets carry a +1
 * adjustment (offset 0 => `col-start-1`), so this also proves the map is built
 * from the adapter's real offset logic, not a passthrough of the loop index.
 *
 * The unit-tier {@see \WeDevelop\Grid\Tests\Unit\Value\AdapterConfigTest} owns
 * the adapter-independent logic (map keying, the empty-viewport invariant, the
 * JSON wire shape); this proves that logic integrates with a concrete
 * framework's configuration. Needs the SilverStripe config manifest booted,
 * which a plain TestCase does not provide — hence the integration tier.
 */
#[CoversClass(AdapterConfig::class)]
final class AdapterConfigTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testFromTailwindAdapterYieldsTailwindClassVocabulary(): void
    {
        $config = AdapterConfig::fromAdapter(new TailwindAdapter());

        self::assertSame(12, $config->columnCount);
        self::assertSame('sm', $config->defaultViewport->key);
        self::assertSame(OffsetStrategy::GridPlacement, $config->offsetStrategy);
        self::assertSame('grid grid-cols-12', $config->rowClasses);

        self::assertSame('col-span-1', $config->baseWidthClasses[1]);
        self::assertSame('col-span-12', $config->baseWidthClasses[12]);
        // offset_adjustment = 1: index 0 => col-start-1, index 11 => col-start-12.
        self::assertSame('col-start-1', $config->baseOffsetClasses[0]);
        self::assertSame('col-start-12', $config->baseOffsetClasses[11]);

        self::assertSame(
            ['base', 'sm', 'md', 'lg', 'xl', '2xl'],
            array_map(static fn (Viewport $viewport): string => $viewport->key, $config->viewports),
        );
    }
}
