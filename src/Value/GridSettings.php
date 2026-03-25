<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;

/**
 * Immutable value object for column grid settings.
 *
 * Stores a default viewport configuration and per-viewport overrides.
 * The default maps to the adapter's default viewport; overrides are
 * keyed by viewport key and represent explicit user customizations.
 *
 */
final readonly class GridSettings implements JsonSerializable
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

    /**
     * Decode from a JSON string (database storage).
     *
     * Handles the new format (`{"default": ..., "overrides": ...}`)
     * and falls back to initial defaults for empty/invalid input.
     *
     * @param positive-int $columnCount Fallback column count when JSON is empty/invalid
     */
    public static function fromJson(string $json, int $columnCount): self
    {
        if (in_array($json, ['', '{}', '[]'], true)) {
            return self::initial($columnCount);
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['default'])) {
            return self::initial($columnCount);
        }

        /** @var array{width: positive-int, offset: int<0, max>, visible: bool} $defaultData */
        $defaultData = $decoded['default'];
        $default = ViewportConfig::fromArray($defaultData);

        $overrides = [];
        if (isset($decoded['overrides']) && is_array($decoded['overrides'])) {
            foreach ($decoded['overrides'] as $key => $data) {
                if (is_string($key) && $key !== '' && is_array($data)) {
                    /** @var array{width: positive-int, offset: int<0, max>, visible: bool} $data */
                    $overrides[$key] = ViewportConfig::fromArray($data);
                }
            }
        }

        /** @var array<non-empty-string, ViewportConfig> $overrides */
        return new self($default, $overrides);
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

    // ─── Persistence ───────────────────────────────────────────

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{default: array{width: positive-int, offset: int<0, max>, visible: bool}, overrides: array<non-empty-string, array{width: positive-int, offset: int<0, max>, visible: bool}>}
     */
    public function jsonSerialize(): array
    {
        $overrides = [];
        foreach ($this->overrides as $key => $config) {
            $overrides[$key] = $config->toArray();
        }

        /** @var array<non-empty-string, array{width: positive-int, offset: int<0, max>, visible: bool}> $overrides */
        return [
            'default' => $this->default->toArray(),
            'overrides' => $overrides,
        ];
    }
}
