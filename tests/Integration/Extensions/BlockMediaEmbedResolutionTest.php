<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Extensions\Support\BlockMediaExtensionSwapTestCase;
use WeDevelop\Grid\Tests\Integration\Extensions\Support\RecordingBlockMediaExtension;

/**
 * Pins the onBeforeWrite guard `$changed && $videoUrl !== ''` by swapping the
 * real oEmbed resolver for a call counter, so no test touches the network.
 */
#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaEmbedResolutionTest extends BlockMediaExtensionSwapTestCase
{
    protected static $illegal_extensions = [
        ContentElement::class => [BlockMediaExtension::class],
    ];

    protected static $required_extensions = [
        ContentElement::class => [RecordingBlockMediaExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        RecordingBlockMediaExtension::reset();
    }

    public function testOnBeforeWriteResolvesEmbedWhenURLChangedAndNonEmpty(): void
    {
        $element = $this->createContentElement();
        $element->write();
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls, 'Initial write with empty URL must not resolve');

        $element->VideoURL = 'https://youtube.com/watch?v=abc';
        $element->write();

        // Pins `$changed && $videoUrl !== ''`: both sub-expressions must be true
        self::assertSame(1, RecordingBlockMediaExtension::$resolveCalls);
    }

    public function testOnBeforeWriteSkipsResolveWhenURLUnchanged(): void
    {
        $element = $this->createContentElement();
        $element->VideoURL = 'https://youtube.com/watch?v=abc';
        $element->write();

        RecordingBlockMediaExtension::reset();
        $element->Title = 'Updated title';
        $element->write();

        // `$changed` is false → guard short-circuits; mutated LogicalAnd `||` would wrongly call
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls);
    }

    public function testOnBeforeWriteSkipsResolveWhenURLChangedToEmpty(): void
    {
        $element = $this->createContentElement();
        $element->VideoURL = 'https://youtube.com/watch?v=abc';
        $element->write();

        RecordingBlockMediaExtension::reset();
        $element->VideoURL = '';
        $element->write();

        // `$videoUrl !== ''` is false → guard short-circuits; any mutation replacing `!==` with
        // `===` or flipping the && would call resolve here
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls);
    }

    public function testOnBeforeWriteSkipsResolveWhenUnchangedAndEmpty(): void
    {
        $element = $this->createContentElement();
        $element->VideoURL = '';
        $element->write();
        RecordingBlockMediaExtension::reset();

        $element->Title = 'Something';
        $element->write();

        // Both sub-expressions false — LogicalAndAllSubExprNegation flips to `!$changed && !(url!=='')`
        // which would be true here and call resolve
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls);
    }
}
