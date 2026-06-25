<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use SilverStripe\CMS\Model\SiteTree;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ViewportConfig;
use WeDevelop\Grid\Value\WriteResult;

/**
 * Domain service for column grid settings management.
 *
 * Handles viewport-aware settings updates and bulk override resets.
 * Follows the same pattern as {@see ElementPlacementService}: receives already-loaded,
 * already-authorized objects and returns {@see Result} for domain validation failures.
 */
final readonly class GridSettingsService
{
    public function __construct(
        private GridAdapterInterface $gridAdapter,
        private GridTreeBuilder $treeBuilder,
    ) {
    }

    /**
     * Update grid settings for a column at a specific viewport.
     *
     * When the viewport matches the adapter's default, the default config is replaced.
     * For other viewports: if the new values match the default, the override is removed
     * (redundant override cleanup); otherwise the override is set.
     *
     * @param non-empty-string $viewport
     * @return Result<GridElement>
     */
    #[NoDiscard('The Result reports write/validation failures; discarding it silently swallows them.')]
    public function updateSettings(Column $column, string $viewport, int $width, int $offset, bool $visible): Result
    {
        $settings = $column->getGridSettings();
        $defaultKey = $this->gridAdapter->getDefaultViewport()->key;
        $values = new ViewportConfig($width, $offset, $visible);

        if ($viewport === $defaultKey) {
            $settings = $settings->withDefault($values);
        } else {
            $settings = $values->equals($settings->default)
                ? $settings->withoutOverride($viewport)
                : $settings->withOverride($viewport, $values);
        }

        $column->setGridSettings($settings);

        /** @var Result<GridElement> */
        return WriteResult::from(static function () use ($column): GridElement {
            $column->write();

            return $column;
        });
    }

    /**
     * Reset grid settings overrides for all columns on a page within a zone.
     *
     * When a viewport is specified, only that viewport's override is removed.
     * When null, all overrides are removed from each column.
     * Columns without relevant overrides are skipped.
     *
     * @param non-empty-string $zone
     * @param non-empty-string|null $viewport
     * @return Result<int> Count of columns that were modified
     */
    #[NoDiscard('The Result reports write/validation failures and the modified-column count; discarding it silently swallows them.')]
    public function resetOverrides(SiteTree $page, string $zone, ?string $viewport): Result
    {
        $columns = $this->treeBuilder->findColumnsForPage($page, $zone);

        // Wrap the whole loop in a single transaction: a mid-loop write failure
        // rolls back every preceding column reset so a page can never be left
        // partially reset.
        return Transactional::run(fn (): Result => $this->resetColumns($columns, $viewport));
    }

    /**
     * Reset overrides on each column and persist it. Returns the count of
     * modified columns, or the first write failure as an err Result.
     *
     * No transaction awareness — {@see resetOverrides()} owns the enclosing
     * transaction so all writes commit or roll back atomically.
     *
     * @param list<Column> $columns
     * @param non-empty-string|null $viewport
     * @return Result<int>
     */
    private function resetColumns(array $columns, ?string $viewport): Result
    {
        $affected = 0;

        foreach ($columns as $column) {
            $settings = $column->getGridSettings();

            if ($viewport !== null) {
                if (!$settings->hasOverride($viewport)) {
                    continue;
                }
                $settings = $settings->withoutOverride($viewport);
            } else {
                if ($settings->overrides === []) {
                    continue;
                }
                $settings = $settings->withoutOverrides();
            }

            $column->setGridSettings($settings);

            $result = WriteResult::from(static function () use ($column): GridElement {
                $column->write();

                return $column;
            });

            if ($result->isErr()) {
                return Result::fail(...$result->errors());
            }

            ++$affected;
        }

        /** @var Result<int> */
        return Result::ok($affected);
    }
}
