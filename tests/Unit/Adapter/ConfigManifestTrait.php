<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Adapter;

use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Core\Config\ConfigLoader;

/**
 * Bootstraps a minimal SilverStripe config manifest for adapter unit tests.
 *
 * Adapters use the Configurable trait which reads from Config::inst().
 * Without a manifest, static::config() throws. A MemoryConfigCollection
 * returns null for all config lookups (the adapters' default behavior).
 */
trait ConfigManifestTrait
{
    protected function pushConfigManifest(): void
    {
        ConfigLoader::inst()->pushManifest(new MemoryConfigCollection());
    }

    protected function popConfigManifest(): void
    {
        ConfigLoader::inst()->popManifest();
    }
}
