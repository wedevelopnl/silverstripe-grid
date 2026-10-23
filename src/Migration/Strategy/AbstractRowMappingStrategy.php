<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;

/**
 * Shared inputs and column formation for the row-mapping strategies.
 *
 * The strategies differ only in how legacy row groups map onto Sections and
 * Rows; how a group's elements become Columns is a migration invariant
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
     * One Column per legacy element, carrying that element's own grid settings.
     *
     * Consecutive elements with identical settings are deliberately not merged:
     * a shared Column stacks its elements, which turned legacy side-by-side
     * blocks into a vertical stack.
     *
     * @param list<LegacyElement> $elements
     * @return list<MigrationColumn>
     */
    protected function buildColumns(array $elements): array
    {
        $columns = [];
        foreach ($elements as $index => $element) {
            $columns[] = new MigrationColumn(
                gridSettings: $this->mapper->mapGridSettings($element, $this->defaultViewport, $this->viewportKeyMap),
                sort: $index + 1,
                element: $element,
            );
        }

        return $columns;
    }
}
