<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Factory\GridAdapterFactory;

#[CoversClass(GridAdapterFactory::class)]
final class GridAdapterFactoryTest extends SapphireTest
{
    protected $usesDatabase = false;

    private string|false $previousEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = Environment::getEnv('SS_GRID_ADAPTER');
        Environment::putEnv('SS_GRID_ADAPTER=bootstrap');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === false) {
            Environment::putEnv('SS_GRID_ADAPTER=');
        } else {
            Environment::putEnv('SS_GRID_ADAPTER=' . $this->previousEnv);
        }

        parent::tearDown();
    }

    public function testCreateReturnsTheGridAdapterSingleton(): void
    {
        $singleton = Injector::inst()->get(GridAdapterInterface::class);

        $created = (new GridAdapterFactory())->create(GridAdapterInterface::class);

        self::assertSame($singleton, $created);
    }

    public function testContentLayoutInterfaceResolvesToSameInstanceAsGridAdapter(): void
    {
        self::assertSame(
            Injector::inst()->get(GridAdapterInterface::class),
            Injector::inst()->get(ContentLayoutAdapterInterface::class),
        );
    }
}
