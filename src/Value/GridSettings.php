<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;
use Override;

/**
 * Stores a default viewport configuration and per-viewport overrides.
 * The default maps to the adapter's default viewport; overrides are
 * keyed by viewport key and represent explicit user customizations.
 *
 * JSON output uses `JsonSerializable`: `json_encode($gridSettings)` yields
 * `{default: {...}, overrides: {...}}` — or `overrides: []` when the map is
 * empty, because PHP encodes an empty associative array as a JSON array.
 * {@see fromJson} accepts either shape for fixture/legacy input. Storage-
 * layer framing for the split composite DB column lives in
 * {@see \WeDevelop\Grid\ORM\FieldType\DBGridSettings}.
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

    /**
     * @param positive-int $columnCount
     */
    public static function initial(int $columnCount): self
    {
        return new self(ViewportConfig::default($columnCount));
    }

    /**
     * Returns null for empty/invalid JSON, a missing or non-array `default`,
     * or a non-positive `default.width` — the last is retained for backward
     * compatibility with legacy fixtures that encode "unset" as `width: 0`.
     *
     * Throws {@see InvalidGridValueException} when a `default` (or override)
     * payload is present but structurally malformed — missing required keys
     * or wrong scalar types. A silent null would mask such bugs behind a
     * downstream `TypeError`.
     */
    public static function fromJson(string $raw): ?self
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['default']) || !is_array($decoded['default'])) {
            return null;
        }

        /** @var array<string, mixed> $defaultData */
        $defaultData = $decoded['default'];
        if (isset($defaultData['width']) && is_int($defaultData['width']) && $defaultData['width'] <= 0) {
            return null;
        }

        $default = ViewportConfig::fromArray($defaultData, 'default');

        $overrides = [];
        if (isset($decoded['overrides']) && is_array($decoded['overrides'])) {
            $overrides = ViewportConfig::mapFromArray($decoded['overrides'], 'overrides');
        }

        return new self($default, $overrides);
    }

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

    /**
     * Two GridSettings are equal when their defaults match, they have
     * the same override keys, and every override value matches.
     */
    public function equals(self $other): bool
    {
        if (!$this->default->equals($other->default)) {
            return false;
        }

        if (\count($this->overrides) !== \count($other->overrides)) {
            return false;
        }

        foreach ($this->overrides as $key => $config) {
            if (!isset($other->overrides[$key]) || !$config->equals($other->overrides[$key])) {
                return false;
            }
        }

        return true;
    }

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

    public function withoutOverrides(): self
    {
        return new self($this->default);
    }

    /**
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

    /**
     * @return array{default: array{width: int, offset: int, visible: bool}, overrides: array<non-empty-string, array{width: int, offset: int, visible: bool}>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
