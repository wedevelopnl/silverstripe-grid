<?php declare(strict_types=1);

namespace WeDevelop\Grid\Factory;

use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injector;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;

/**
 * Resolves the active {@see GridAdapterInterface} binding from the SS_GRID_ADAPTER
 * environment variable.
 *
 * Accepts symbolic preset names (bootstrap|tailwind|bulma), case-insensitive.
 * Unset or empty defaults to bootstrap, preserving the pre-refactor behaviour.
 * Projects with a custom adapter can still rebind GridAdapterInterface directly
 * in YAML to sidestep this factory entirely.
 */
final class GridAdapterResolver implements Factory
{
    private const ENV_VAR = 'SS_GRID_ADAPTER';

    private const DEFAULT_PRESET = 'bootstrap';

    /** @var array<string, class-string<GridAdapterInterface>> */
    private const PRESETS = [
        'bootstrap' => BootstrapAdapter::class,
        'tailwind' => TailwindAdapter::class,
        'bulma' => BulmaAdapter::class,
    ];

    /** @param array<int|string, mixed> $params */
    public function create(string $service, array $params = []): object
    {
        $raw = Environment::getEnv(self::ENV_VAR);
        $preset = is_string($raw) && $raw !== '' ? strtolower($raw) : self::DEFAULT_PRESET;

        if (!isset(self::PRESETS[$preset])) {
            throw new RuntimeException(sprintf(
                'Unknown %s preset "%s". Expected one of: %s.',
                self::ENV_VAR,
                $preset,
                implode(', ', array_keys(self::PRESETS)),
            ));
        }

        return Injector::inst()->create(self::PRESETS[$preset]);
    }
}
