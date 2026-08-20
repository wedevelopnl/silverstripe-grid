<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Config\Config;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Suppresses container auto-scaffolding so tests can build explicit trees.
 *
 * SapphireTest snapshots config per test, so a setUp() disable is undone
 * automatically; tests that exercise scaffolding itself re-enable locally
 * via enableAutoScaffolding().
 */
trait DisablesAutoScaffolding
{
    protected function disableAutoScaffolding(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    protected function enableAutoScaffolding(): void
    {
        Config::modify()->set(Section::class, 'auto_scaffold', true);
        Config::modify()->set(Row::class, 'auto_scaffold', true);
    }
}
