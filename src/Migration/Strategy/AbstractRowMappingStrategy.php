<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Value\GridSettings;

/**
 * Shared inputs and column grouping for the row-mapping strategies.
 *
 * The strategies differ only in how legacy row groups map onto Sections and
 * Rows; how a group's elements collapse into Columns is a migration invariant
 * both must apply identically — {@see LivePublisher}'s live reconciliation
 * depends on it — so it lives here rather than in each subclass.
 */
abstract readonly class AbstractRowMappingStrategy implements RowMappingStrategy
{
    /**
     * @param non-empty-string      $defaultViewport
     * @param array<string, string> $viewportKeyMap  Old viewport key → new key (e.g. 'MD' → 'md')
     */
    public function __construct(
        protected ElementGrouper $grouper,
        protected FieldMapper $mapper,
        protected string $defaultViewport,
        protected array $viewportKeyMap,
    ) {}

    /**
     * Group consecutive elements with identical GridSettings into shared columns.
     *
     * @param list<LegacyElement> $elements
     * @return list<MigrationColumn>
     */
    protected function buildColumns(array $elements): array
    {
        /** @var list<GridSettings> $groupSettings */
        $groupSettings = [];
        /** @var list<list<LegacyElement>> $groupElements */
        $groupElements = [];

        foreach ($elements as $element) {
            $gridSettings = $this->mapper->mapGridSettings($element, $this->defaultViewport, $this->viewportKeyMap);
            $lastIndex = \count($groupSettings) - 1;

            if ($lastIndex >= 0 && $gridSettings->equals($groupSettings[$lastIndex])) {
                $groupElements[$lastIndex][] = $element;
            } else {
                $groupSettings[] = $gridSettings;
                $groupElements[] = [$element];
            }
        }

        $columns = [];
        foreach ($groupSettings as $index => $settings) {
            $columns[] = new MigrationColumn(
                gridSettings: $settings,
                sort: $index + 1,
                elements: $groupElements[$index],
            );
        }

        return $columns;
    }
}
