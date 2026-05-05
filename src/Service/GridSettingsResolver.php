<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\OverrideStrategy;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Resolves effective ViewportConfig per viewport from GridSettings + override strategy.
 *
 * This is the single place where the override strategy (isolated vs cascade) is applied.
 * The result is a flat map of viewport key → effective ViewportConfig, ready for
 * CSS class generation by ColumnClassResolver.
 *
 * The override strategy is configured via DI constructor injection:
 * ```yaml
 * WeDevelop\Grid\Service\GridSettingsResolver:
 *   constructor:
 *     overrideStrategy: cascade
 * ```
 */
final readonly class GridSettingsResolver
{
    private OverrideStrategy $strategy;

    /**
     * @param non-empty-string $overrideStrategy 'isolated' or 'cascade'
     * @throws InvalidGridValueException If the strategy string is not valid
     */
    public function __construct(
        private GridAdapterInterface $adapter,
        string $overrideStrategy = 'isolated',
    ) {
        $strategy = OverrideStrategy::tryFrom($overrideStrategy);

        if ($strategy === null) {
            throw InvalidGridValueException::forOverrideStrategy($overrideStrategy);
        }

        $this->strategy = $strategy;
    }

    /**
     * @return array<non-empty-string, ViewportConfig>
     */
    public function resolveEffective(GridSettings $settings): array
    {
        return match ($this->strategy) {
            OverrideStrategy::Isolated => $this->resolveIsolated($settings),
            OverrideStrategy::Cascade => $this->resolveCascade($settings),
        };
    }

    /**
     * Isolated: each viewport independently uses its override or the default.
     *
     * @return array<non-empty-string, ViewportConfig>
     */
    private function resolveIsolated(GridSettings $settings): array
    {
        $result = [];

        foreach ($this->adapter->getViewports() as $viewport) {
            $result[$viewport->key] = $settings->forViewport($viewport->key);
        }

        return $result;
    }

    /**
     * Cascade: an override at viewport X applies from the smallest viewport up to X.
     *
     * Walk viewports largest-to-smallest, tracking the "current" effective config.
     * When an override is found, it becomes current. Each viewport gets the current value.
     * Viewports above the highest override use the default.
     *
     * @return array<non-empty-string, ViewportConfig>
     */
    private function resolveCascade(GridSettings $settings): array
    {
        $viewports = $this->adapter->getViewports();

        // Walk largest-to-smallest
        $current = $settings->default;
        $result = [];

        for ($i = count($viewports) - 1; $i >= 0; $i--) {
            $key = $viewports[$i]->key;
            $override = $settings->getOverride($key);

            if ($override !== null) {
                $current = $override;
            }

            $result[$key] = $current;
        }

        // Reverse to restore smallest-to-largest order
        return array_reverse($result, true);
    }
}
