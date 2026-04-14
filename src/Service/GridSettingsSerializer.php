<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Pure serialization/deserialization for GridSettings.
 *
 * Handles JSON ↔ GridSettings conversion for fixture loading and override storage.
 */
final class GridSettingsSerializer
{
    /**
     * Parse a JSON string into a GridSettings value object.
     *
     * Returns null for empty/invalid JSON or when the `default` key is absent.
     * Throws {@see InvalidGridValueException} when a `default` (or override)
     * payload is present but structurally malformed — missing required keys
     * or carrying the wrong scalar types. A silent null in that case masked
     * bugs behind a downstream `TypeError`; an explicit domain error surfaces
     * the bad write at its origin instead.
     */
    public static function fromJson(string $raw): ?GridSettings
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['default']) || !is_array($decoded['default'])) {
            return null;
        }

        /** @var array<string, mixed> $defaultData */
        $defaultData = $decoded['default'];
        // Width zero/negative keeps the tolerant null-return for backward
        // compatibility with legacy fixture loaders; structural errors still
        // throw via parseViewportConfig below.
        if (isset($defaultData['width']) && is_int($defaultData['width']) && $defaultData['width'] <= 0) {
            return null;
        }

        $default = self::parseViewportConfig($defaultData, 'default');

        $overrides = [];
        if (isset($decoded['overrides']) && is_array($decoded['overrides'])) {
            foreach ($decoded['overrides'] as $key => $data) {
                if (!is_string($key) || $key === '' || !is_array($data)) {
                    continue;
                }
                /** @var array<string, mixed> $data */
                $overrides[$key] = self::parseViewportConfig($data, sprintf('overrides["%s"]', $key));
            }
        }

        /** @var array<non-empty-string, ViewportConfig> $overrides */
        return new GridSettings($default, $overrides);
    }

    /**
     * Serialize viewport overrides to JSON for storage.
     *
     * Returns null when there are no overrides — no empty strings or
     * empty JSON objects are stored.
     *
     * @param array<non-empty-string, ViewportConfig> $overrides
     */
    public static function serializeOverrides(array $overrides): ?string
    {
        if ($overrides === []) {
            return null;
        }

        $data = [];
        foreach ($overrides as $key => $config) {
            $data[$key] = $config->toArray();
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Deserialize viewport overrides from JSON.
     *
     * @return array<non-empty-string, ViewportConfig>
     */
    public static function deserializeOverrides(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $overrides = [];
        foreach ($decoded as $key => $data) {
            if (!is_string($key) || $key === '' || !is_array($data)) {
                continue;
            }
            /** @var array<string, mixed> $data */
            $overrides[$key] = self::parseViewportConfig($data, sprintf('overrides["%s"]', $key));
        }

        /** @var array<non-empty-string, ViewportConfig> $overrides */
        return $overrides;
    }

    /**
     * Validate and materialise a single viewport payload.
     *
     * @param array<string, mixed> $data
     */
    private static function parseViewportConfig(array $data, string $context): ViewportConfig
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

        /** @var array{width: int, offset: int, visible: bool} $data */
        return ViewportConfig::fromArray($data);
    }
}
