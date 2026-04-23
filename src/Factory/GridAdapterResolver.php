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
 * Accepts either a bundled preset name (bootstrap|tailwind|bulma, case-insensitive)
 * or the fully-qualified class name of a custom adapter that implements
 * {@see GridAdapterInterface}. The variable is required — unset, empty, or
 * invalid values throw.
 */
final class GridAdapterResolver implements Factory
{
    private const ENV_VAR = 'SS_GRID_ADAPTER';

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

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(sprintf(
                '%s environment variable is not set. Expected a preset (%s) or an FQCN implementing %s.',
                self::ENV_VAR,
                implode('|', array_keys(self::PRESETS)),
                GridAdapterInterface::class,
            ));
        }

        $class = self::PRESETS[strtolower($raw)] ?? $raw;

        if (!is_subclass_of($class, GridAdapterInterface::class)) {
            throw new RuntimeException(sprintf(
                'Invalid %s value "%s". Expected a preset (%s) or an FQCN implementing %s.',
                self::ENV_VAR,
                $raw,
                implode('|', array_keys(self::PRESETS)),
                GridAdapterInterface::class,
            ));
        }

        return Injector::inst()->create($class);
    }
}
