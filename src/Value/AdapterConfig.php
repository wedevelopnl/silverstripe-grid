<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use InvalidArgumentException;
use JsonSerializable;
use Override;
use stdClass;
use WeDevelop\Grid\Contract\GridAdapterInterface;

/**
 * The CMS grid editor needs the active adapter's viewports, column count, and
 * base CSS class maps as plain JSON. This value object is the single boundary
 * between {@see GridAdapterInterface} (PHP) and the editor's `AdapterConfig`
 * TypeScript shape: {@see fromAdapter()} reads the adapter once and
 * {@see jsonSerialize()} emits the exact wire shape the frontend parses.
 *
 * The two base class maps serialize as JSON *objects*, not arrays — the offset
 * map in particular is keyed 0..N-1, which a plain PHP array would encode as a
 * JSON array. {@see jsonSerialize()} casts both to {@see stdClass} so the
 * frontend's `Record<string, string>` contract holds.
 *
 * @phpstan-import-type SerializedViewport from Viewport
 * @phpstan-type SerializedAdapterConfig array{
 *     viewports: list<SerializedViewport>,
 *     defaultViewport: non-empty-string,
 *     columnCount: positive-int,
 *     rowClasses: string,
 *     offsetStrategy: 'margin'|'grid-placement',
 *     baseWidthClasses: stdClass,
 *     baseOffsetClasses: stdClass,
 * }
 */
final readonly class AdapterConfig implements JsonSerializable
{
    /**
     * @param non-empty-list<Viewport>    $viewports
     * @param positive-int                $columnCount
     * @param array<positive-int, string> $baseWidthClasses  Keyed by column span (1..columnCount)
     * @param array<int<0, max>, string>  $baseOffsetClasses Keyed by offset (0..columnCount-1)
     */
    public function __construct(
        public array $viewports,
        public Viewport $defaultViewport,
        public int $columnCount,
        public string $rowClasses,
        public OffsetStrategy $offsetStrategy,
        public array $baseWidthClasses,
        public array $baseOffsetClasses,
    ) {}

    /**
     * @throws InvalidArgumentException when the adapter declares no viewports —
     *         the editor cannot render a grid without at least one breakpoint.
     */
    public static function fromAdapter(GridAdapterInterface $adapter): self
    {
        $viewports = $adapter->getViewports();

        if ($viewports === []) {
            throw new InvalidArgumentException('Adapter must define at least one viewport.');
        }

        $columnCount = $adapter->getColumnCount();

        $baseWidthClasses = [];
        for ($width = 1; $width <= $columnCount; ++$width) {
            $baseWidthClasses[$width] = $adapter->getBaseWidthClass($width);
        }

        $baseOffsetClasses = [];
        for ($offset = 0; $offset < $columnCount; ++$offset) {
            $baseOffsetClasses[$offset] = $adapter->getBaseOffsetClass($offset);
        }

        return new self(
            $viewports,
            $adapter->getDefaultViewport(),
            $columnCount,
            $adapter->getRowClasses(),
            $adapter->getOffsetStrategy(),
            $baseWidthClasses,
            $baseOffsetClasses,
        );
    }

    /**
     * @return SerializedAdapterConfig
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'viewports' => array_map(
                static fn (Viewport $viewport): array => $viewport->jsonSerialize(),
                $this->viewports,
            ),
            'defaultViewport' => $this->defaultViewport->key,
            'columnCount' => $this->columnCount,
            'rowClasses' => $this->rowClasses,
            'offsetStrategy' => $this->offsetStrategy->value,
            'baseWidthClasses' => (object) $this->baseWidthClasses,
            'baseOffsetClasses' => (object) $this->baseOffsetClasses,
        ];
    }
}
