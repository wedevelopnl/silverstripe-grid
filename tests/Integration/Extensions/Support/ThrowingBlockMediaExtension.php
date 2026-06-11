<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions\Support;

use RuntimeException;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Extensions\BlockMediaExtension;

/**
 * Test-only subclass of BlockMediaExtension whose embed resolver always throws,
 * simulating a transient oEmbed network failure or a malformed-but-non-empty
 * VideoURL. Lets tests assert onBeforeWrite swallows the failure (logs + lets
 * the write proceed) without hitting the real network path.
 */
final class ThrowingBlockMediaExtension extends BlockMediaExtension implements TestOnly
{
    public const FAILURE_MESSAGE = 'simulated oEmbed failure';

    protected function resolveVideoEmbed(DataObject $owner): void
    {
        throw new RuntimeException(self::FAILURE_MESSAGE);
    }
}
