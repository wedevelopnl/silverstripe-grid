<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Extensions\Support\BlockMediaExtensionSwapTestCase;
use WeDevelop\Grid\Tests\Integration\Extensions\Support\ThrowingBlockMediaExtension;

/**
 * Swaps in an embed resolver that always throws, simulating a transient oEmbed
 * network failure without touching the network. The throw must be caught inside
 * onBeforeWrite so the save still completes.
 */
#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaEmbedFailureTest extends BlockMediaExtensionSwapTestCase
{
    protected static $illegal_extensions = [
        ContentElement::class => [BlockMediaExtension::class],
    ];

    protected static $required_extensions = [
        ContentElement::class => [ThrowingBlockMediaExtension::class],
    ];

    public function testOnBeforeWriteSucceedsWhenEmbedResolutionThrows(): void
    {
        $element = $this->createContentElement();
        $element->VideoURL = 'https://youtube.com/watch?v=throws';
        $element->write();

        // A throwing embed resolver must not abort the save: the record persists
        // (gets an ID) and the trimmed URL is stored, just without embed metadata.
        self::assertGreaterThan(0, (int) $element->ID);
        self::assertSame('https://youtube.com/watch?v=throws', $element->VideoURL);
        self::assertSame('', (string) $element->VideoEmbedName);
    }
}
