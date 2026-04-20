<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Extensions\BlockMediaExtension;

/**
 * Test-only subclass of BlockMediaExtension that replaces the real oEmbed
 * resolver with a call counter. Lets tests pin the `onBeforeWrite` guard
 * (`$changed && $videoUrl !== ''`) against mutations without hitting the
 * network.
 */
final class RecordingBlockMediaExtension extends BlockMediaExtension implements TestOnly
{
    public static int $resolveCalls = 0;

    public static function reset(): void
    {
        self::$resolveCalls = 0;
    }

    protected function resolveVideoEmbed(DataObject $owner): void
    {
        ++self::$resolveCalls;
    }
}
