<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Task;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\InputOption;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Migration\Task\AbstractMigrationTask;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Value\Viewport;

/**
 * Unit tests for the shared logic on {@see AbstractMigrationTask}.
 *
 * These tests bypass the full migration pipeline and exercise the task's
 * protected helpers directly via reflection against a concrete subclass.
 * The real framework adapter presets are never instantiated here — doing so
 * would pull in the SilverStripe Config bootstrap. Instead, each adapter
 * topology is expressed as a stubbed {@see GridAdapterInterface} returning a
 * hand-built viewport list, which is all `resolveViewportKeyMap()` reads.
 */
#[CoversClass(AbstractMigrationTask::class)]
final class AbstractMigrationTaskTest extends TestCase
{
    // ─── getOptions: no -f shortcut (sake reserves it for --flush) ───────────

    public function testNoOptionClaimsTheFShortcutReservedBySakeFlush(): void
    {
        // sake registers a global `--flush` with the `-f` shortcut. A task option
        // also claiming `-f` makes Symfony Console throw at registration, which
        // aborts every `sake dev/tasks/<segment>` run. Guard against regressions.
        $shortcuts = \array_filter(\array_map(
            static fn (InputOption $option): ?string => $option->getShortcut(),
            (new MigrateGridTask())->getOptions(),
        ));

        self::assertNotContains('f', $shortcuts);
    }

    // ─── resolveViewportKeyMap: explicit --viewport-map argument ─────────────

    public function testExplicitViewportMapArgumentParsedIntoKeyPairs(): void
    {
        $adapter = $this->adapterWithViewportKeys(['md', 'xl']);

        $map = $this->invokeResolveViewportKeyMap('MD=md,XL=xl', $adapter);

        self::assertSame(['MD' => 'md', 'XL' => 'xl'], $map);
    }

    public function testExplicitViewportMapArgumentTrimsWhitespace(): void
    {
        $adapter = $this->adapterWithViewportKeys(['md', 'xl']);

        $map = $this->invokeResolveViewportKeyMap(' MD = md , XL = xl ', $adapter);

        self::assertSame(['MD' => 'md', 'XL' => 'xl'], $map);
    }

    public function testExplicitViewportMapArgumentSkipsPairsWithoutEquals(): void
    {
        $adapter = $this->adapterWithViewportKeys(['md', 'xl']);

        $map = $this->invokeResolveViewportKeyMap('MD=md,BROKEN,XL=xl', $adapter);

        self::assertSame(['MD' => 'md', 'XL' => 'xl'], $map);
    }

    public function testExplicitViewportMapOldKeyNormalisedToUppercase(): void
    {
        // Legacy sizeFields are keyed uppercase, so a lowercase old key must be
        // normalised to match rather than silently produce a non-matching entry.
        $adapter = $this->adapterWithViewportKeys(['lg']);

        $map = $this->invokeResolveViewportKeyMap('md=lg', $adapter);

        self::assertSame(['MD' => 'lg'], $map);
    }

    public function testExplicitViewportMapArgumentWinsOverAdapterAutoDerive(): void
    {
        // The explicit map overrides the name-matched auto-derive: here MD maps to
        // lg instead of the identity md the auto-derive would produce.
        $adapter = $this->adapterWithViewportKeys(['sm', 'md', 'lg']);

        $map = $this->invokeResolveViewportKeyMap('MD=lg', $adapter);

        self::assertSame(['MD' => 'lg'], $map);
    }

    public function testExplicitViewportMapRejectsUnknownLegacyOldKey(): void
    {
        $adapter = $this->adapterWithViewportKeys(['md']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --viewport-map old key "ZZ"');

        $this->invokeResolveViewportKeyMap('ZZ=md', $adapter);
    }

    public function testExplicitViewportMapRejectsNewKeyNotEnabledOnAdapter(): void
    {
        // Mapping a legacy key to a viewport the active adapter does not expose
        // would target a nonexistent breakpoint and silently drop the override.
        $adapter = $this->adapterWithViewportKeys(['md']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --viewport-map new key "xl"');

        $this->invokeResolveViewportKeyMap('MD=md,XL=xl', $adapter);
    }

    // ─── resolveViewportKeyMap: auto-derive from adapter ────────────────────

    /**
     * Each case: [adapterViewportKeys, expectedKeyMap].
     *
     * @return iterable<string, array{list<string>, array<string, string>}>
     */
    public static function autoDeriveProvider(): iterable
    {
        yield 'Bootstrap — full identity map' => [
            ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'],
            ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'],
        ];

        yield 'Tailwind — XS has no equivalent and is dropped' => [
            ['sm', 'md', 'lg', 'xl', '2xl'],
            ['SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'],
        ];

        yield 'Bulma — no overlap with Bootstrap legacy keys' => [
            ['mobile', 'tablet', 'desktop', 'widescreen', 'fullhd'],
            [],
        ];

        yield 'Uppercase adapter keys — matched case-insensitively' => [
            ['SM', 'MD', 'LG'],
            ['SM' => 'SM', 'MD' => 'MD', 'LG' => 'LG'],
        ];

        yield 'Adapter with only MD — only MD maps' => [
            ['md'],
            ['MD' => 'md'],
        ];

        yield 'Empty adapter viewport list — empty map' => [
            [],
            [],
        ];
    }

    /**
     * @param list<string>              $adapterViewportKeys
     * @param array<string, string>     $expectedKeyMap
     */
    #[DataProvider('autoDeriveProvider')]
    public function testAutoDerivesViewportKeyMapFromAdapter(array $adapterViewportKeys, array $expectedKeyMap): void
    {
        $adapter = $this->adapterWithViewportKeys($adapterViewportKeys);

        $map = $this->invokeResolveViewportKeyMap(null, $adapter);

        self::assertSame($expectedKeyMap, $map);
    }

    public function testAutoDerivesWhenArgumentIsEmptyString(): void
    {
        // Empty string is treated the same as null (no argument passed)
        $adapter = $this->adapterWithViewportKeys(['sm', 'md', 'lg', 'xl', '2xl']);

        $map = $this->invokeResolveViewportKeyMap('', $adapter);

        self::assertSame(['SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'], $map);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a stubbed {@see GridAdapterInterface} that returns {@see Viewport}
     * instances for the given keys. Only `getViewports()` is relevant to
     * `resolveViewportKeyMap()`.
     *
     * @param list<string> $keys
     */
    private function adapterWithViewportKeys(array $keys): GridAdapterInterface
    {
        $viewports = \array_map(
            static fn (string $key): Viewport => new Viewport($key, \ucfirst($key), 0),
            $keys,
        );

        $adapter = $this->createStub(GridAdapterInterface::class);
        $adapter->method('getViewports')->willReturn($viewports);

        return $adapter;
    }

    /**
     * Invoke the protected {@see AbstractMigrationTask::resolveViewportKeyMap()}
     * on a concrete task subclass via reflection.
     *
     * @return array<string, string>
     */
    private function invokeResolveViewportKeyMap(?string $viewportMapArg, GridAdapterInterface $adapter): array
    {
        $task = new MigrateGridTask();
        $method = new ReflectionMethod(AbstractMigrationTask::class, 'resolveViewportKeyMap');
        /** @var array<string, string> $result */
        $result = $method->invoke($task, $viewportMapArg, $adapter);
        return $result;
    }
}
