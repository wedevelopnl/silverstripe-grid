<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Immutable value object for a single viewport's grid configuration.
 */
final readonly class ViewportConfig
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
     * @param array{width: int, offset: int, visible: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['width'],
            $data['offset'],
            $data['visible'],
        );
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

    public function equals(self $other): bool
    {
        return $this->width === $other->width
            && $this->offset === $other->offset
            && $this->visible === $other->visible;
    }
}
