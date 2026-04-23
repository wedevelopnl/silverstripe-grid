<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;
use Override;
use WeDevelop\Grid\Exception\InvalidGridValueException;

/**
 * Immutable value object for a single viewport's grid configuration.
 */
final readonly class ViewportConfig implements JsonSerializable
{
    public function __construct(
        public int $width,
        public int $offset,
        public bool $visible,
    ) {}

    /**
     * Default configuration: full-width, no offset, visible.
     *
     * @param positive-int $columnCount
     */
    public static function default(int $columnCount): self
    {
        return new self($columnCount, 0, true);
    }

    /**
     * Validate and materialise a viewport payload.
     *
     * Throws {@see InvalidGridValueException} when a required key is missing
     * or a scalar has the wrong type — a silent coerce masks bad writes behind
     * a downstream `TypeError`; the domain error surfaces them at the origin.
     *
     * @param array<string, mixed> $data
     * @param non-empty-string $context label included in error messages, e.g. `default` or `overrides["md"]`
     */
    public static function fromArray(array $data, string $context = 'viewport'): self
    {
        foreach (['width', 'offset', 'visible'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw InvalidGridValueException::forMalformedViewportPayload(
                    $context,
                    sprintf('missing required key "%s"', $key),
                );
            }
        }

        if (!is_int($data['width']) || !is_int($data['offset']) || !is_bool($data['visible'])) {
            throw InvalidGridValueException::forMalformedViewportPayload(
                $context,
                sprintf(
                    'expected {width:int, offset:int, visible:bool}, got {width:%s, offset:%s, visible:%s}',
                    get_debug_type($data['width']),
                    get_debug_type($data['offset']),
                    get_debug_type($data['visible']),
                ),
            );
        }

        return new self($data['width'], $data['offset'], $data['visible']);
    }

    /**
     * @return array{width: int, offset: int, visible: bool}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'offset' => $this->offset,
            'visible' => $this->visible,
        ];
    }

    /**
     * @return array{width: int, offset: int, visible: bool}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function equals(self $other): bool
    {
        return $this->width === $other->width
            && $this->offset === $other->offset
            && $this->visible === $other->visible;
    }
}
