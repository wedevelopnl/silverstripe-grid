<?php declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;

final class BlockMediaExtensionContentLayoutTest extends SapphireTest
{
    public function testContentLayoutInterfaceResolvesToSameSingletonAsGridAdapter(): void
    {
        $grid = Injector::inst()->get(GridAdapterInterface::class);
        $layout = Injector::inst()->get(ContentLayoutAdapterInterface::class);

        self::assertSame($grid, $layout, 'ContentLayoutAdapterInterface must resolve to the same singleton as GridAdapterInterface via DI alias');
    }
}
