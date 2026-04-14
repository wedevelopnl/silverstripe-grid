<?php declare(strict_types=1);

namespace WeDevelop\Grid\Factory;

use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injector;
use WeDevelop\Grid\Contract\GridAdapterInterface;

/**
 * Injector factory that returns the already-resolved {@see GridAdapterInterface} singleton.
 *
 * Used to alias additional service bindings (e.g. ContentLayoutAdapterInterface) to the
 * same underlying grid adapter instance, ensuring all consumers share one object.
 */
final class GridAdapterFactory implements Factory
{
    /** @param array<int|string, mixed> $params */
    public function create(string $service, array $params = []): object
    {
        return Injector::inst()->get(GridAdapterInterface::class);
    }
}
