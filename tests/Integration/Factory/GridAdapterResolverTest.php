<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Factory\GridAdapterResolver;

#[CoversClass(GridAdapterResolver::class)]
final class GridAdapterResolverTest extends SapphireTest
{
    protected $usesDatabase = false;

    private string|false $previousEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = Environment::getEnv('SS_GRID_ADAPTER');
        Environment::putEnv('SS_GRID_ADAPTER=');
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

    public function testUnsetEnvDefaultsToBootstrap(): void
    {
        $adapter = (new GridAdapterResolver())->create(GridAdapterInterface::class);

        self::assertInstanceOf(BootstrapAdapter::class, $adapter);
    }

    /**
     * @param class-string<GridAdapterInterface> $expected
     */
    #[DataProvider('presetProvider')]
    public function testPresetMapsToAdapter(string $preset, string $expected): void
    {
        Environment::putEnv('SS_GRID_ADAPTER=' . $preset);

        $adapter = (new GridAdapterResolver())->create(GridAdapterInterface::class);

        self::assertInstanceOf($expected, $adapter);
    }

    /**
     * @return iterable<string, array{string, class-string<GridAdapterInterface>}>
     */
    public static function presetProvider(): iterable
    {
        yield 'bootstrap' => ['bootstrap', BootstrapAdapter::class];
        yield 'tailwind' => ['tailwind', TailwindAdapter::class];
        yield 'bulma' => ['bulma', BulmaAdapter::class];
        yield 'mixed case is normalised' => ['Tailwind', TailwindAdapter::class];
        yield 'upper case is normalised' => ['BULMA', BulmaAdapter::class];
    }

    public function testUnknownPresetThrows(): void
    {
        Environment::putEnv('SS_GRID_ADAPTER=foundation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown SS_GRID_ADAPTER preset "foundation"');

        (new GridAdapterResolver())->create(GridAdapterInterface::class);
    }
}
