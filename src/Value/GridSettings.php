<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;
use Override;
use WeDevelop\Grid\Exception\InvalidGridValueException;

/**
 * Immutable value object for column grid settings.
 *
 * Stores a default viewport configuration and per-viewport overrides.
 * The default maps to the adapter's default viewport; overrides are
 * keyed by viewport key and represent explicit user customizations.
 *
 * Two JSON formats are supported:
 * - Full: `{default: {...}, overrides: {...}}` — used by the fixture loader
 *   path in {@see \WeDevelop\Grid\ORM\FieldType\DBGridSettings::setValue()}.
 * - Overrides-only: `{md: {...}, lg: {...}}` — stored in the
 *   `{Name}Overrides` Text sub-column of `DBGridSettings`; default viewport
 *   values live in their own typed sub-columns.
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
     * Parse a full-shape JSON payload (`{default, overrides}`) into a GridSettings VO.
     *
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
            $overrides = self::parseOverridesMap($decoded['overrides'], 'overrides');
        }

        return new self($default, $overrides);
    }

    /**
     * Deserialize the overrides-only JSON blob stored in the DB.
     *
     * Tolerant of non-string / non-array input (empty map returned) so that
     * legacy rows with NULL or invalid content do not crash unrelated reads.
     * Structurally malformed override entries still throw — see {@see fromJson}.
     *
     * @return array<non-empty-string, ViewportConfig>
     */
    public static function overridesFromJson(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::parseOverridesMap($decoded, 'overrides');
    }

    /**
     * Serialize overrides for storage in the DB column.
     *
     * Returns null when the map is empty — the DB column stores NULL rather
     * than an empty JSON object, matching the `DBGridSettings` storage contract.
     *
     * @param array<non-empty-string, ViewportConfig> $overrides
     */
    public static function overridesToJson(array $overrides): ?string
    {
        if ($overrides === []) {
            return null;
        }

        return json_encode($overrides, JSON_THROW_ON_ERROR);
    }

    /**
     * Parse a map of viewport payloads.
     *
     * Entries with empty/non-string keys or non-array values are silently
     * skipped (these come from legacy data shapes). Array values that are
     * structurally malformed throw via {@see ViewportConfig::fromArray}.
     *
     * @param array<array-key, mixed> $map
     * @param non-empty-string $basePath
     * @return array<non-empty-string, ViewportConfig>
     */
    private static function parseOverridesMap(array $map, string $basePath): array
    {
        $result = [];
        foreach ($map as $key => $data) {
            if (!is_string($key) || $key === '' || !is_array($data)) {
                continue;
            }
            /** @var array<string, mixed> $data */
            $result[$key] = ViewportConfig::fromArray($data, sprintf('%s["%s"]', $basePath, $key));
        }

        /** @var array<non-empty-string, ViewportConfig> $result */
        return $result;
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

    public function withoutOverrides(): self
    {
        return new self($this->default);
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

    /**
     * @return array{default: array{width: int, offset: int, visible: bool}, overrides: array<non-empty-string, array{width: int, offset: int, visible: bool}>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
