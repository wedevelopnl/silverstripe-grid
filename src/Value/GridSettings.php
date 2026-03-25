<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Immutable value object for column grid settings.
 *
 * Stores a default viewport configuration and per-viewport overrides.
 * The default maps to the adapter's default viewport; overrides are
 * keyed by viewport key and represent explicit user customizations.
 *
 * Storage serialization is handled by {@see \WeDevelop\Grid\ORM\FieldType\DBGridSettings}.
 */
final readonly class GridSettings
{
    /**
     * @param array<non-empty-string, ViewportConfig> $overrides
     */
    public function __construct(
        public ViewportConfig $default,
        public array $overrides = [],
    ) {}

    // ─── Factories ─────────────────────────────────────────────

    /**
     * Initial settings for a new column: full-width, no offset, visible, no overrides.
     *
     * @param positive-int $columnCount
     */
    public static function initial(int $columnCount): self
    {
        return new self(ViewportConfig::default($columnCount));
    }

    // ─── Queries ───────────────────────────────────────────────

    /** @param non-empty-string $viewport */
    public function hasOverride(string $viewport): bool
    {
        return array_key_exists($viewport, $this->overrides);
    }

    /** @param non-empty-string $viewport */
    public function getOverride(string $viewport): ?ViewportConfig
    {
        return $this->overrides[$viewport] ?? null;
    }

    /**
     * Effective config for a viewport: override if present, else default.
     *
     * @param non-empty-string $viewport
     */
    public function forViewport(string $viewport): ViewportConfig
    {
        return $this->overrides[$viewport] ?? $this->default;
    }

    // ─── Immutable updates ─────────────────────────────────────

    public function withDefault(ViewportConfig $config): self
    {
        return new self($config, $this->overrides);
    }

    /** @param non-empty-string $viewport */
    public function withOverride(string $viewport, ViewportConfig $config): self
    {
        return new self($this->default, [...$this->overrides, $viewport => $config]);
    }

    /** @param non-empty-string $viewport */
    public function withoutOverride(string $viewport): self
    {
        $overrides = $this->overrides;
        unset($overrides[$viewport]);

        return new self($this->default, $overrides);
    }

    // ─── Serialization ─────────────────────────────────────────

    /**
     * Convert to array representation for API responses.
     *
     * @return array{default: array{width: int, offset: int, visible: bool}, overrides: array<non-empty-string, array{width: int, offset: int, visible: bool}>}
     */
    public function toArray(): array
    {
        $overrides = [];
        foreach ($this->overrides as $key => $config) {
            $overrides[$key] = $config->toArray();
        }

        /** @var array<non-empty-string, array{width: int, offset: int, visible: bool}> $overrides */
        return [
            'default' => $this->default->toArray(),
            'overrides' => $overrides,
        ];
    }
}
