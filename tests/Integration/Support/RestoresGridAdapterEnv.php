<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Environment;

/**
 * Save/restore of the SS_GRID_ADAPTER env var for tests that pin the active
 * adapter. Call captureGridAdapterEnv() from setUp() before any pin, and
 * restoreGridAdapterEnv() from tearDown() — SapphireTest gives each test a
 * fresh instance, so the captured value never leaks across tests.
 */
trait RestoresGridAdapterEnv
{
    private string|false $previousGridAdapterEnv = false;

    protected function captureGridAdapterEnv(): void
    {
        $this->previousGridAdapterEnv = Environment::getEnv('SS_GRID_ADAPTER');
    }

    protected function pinAdapterEnv(string $value): void
    {
        Environment::putEnv('SS_GRID_ADAPTER=' . $value);
    }

    protected function restoreGridAdapterEnv(): void
    {
        // An unset original (getEnv() === false) restores to the empty string.
        $this->pinAdapterEnv($this->previousGridAdapterEnv === false ? '' : $this->previousGridAdapterEnv);
    }
}
