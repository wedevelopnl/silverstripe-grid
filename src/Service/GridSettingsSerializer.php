<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

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
     * Returns null for empty, invalid, or structurally incomplete JSON.
     */
    public static function fromJson(string $raw): ?GridSettings
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['default']) || !is_array($decoded['default'])) {
            return null;
        }

        $defaultData = $decoded['default'];
        if (!isset($defaultData['width']) || !is_int($defaultData['width']) || $defaultData['width'] <= 0) {
            return null;
        }

        /** @var array{width: int, offset: int, visible: bool} $defaultData */
        $default = ViewportConfig::fromArray($defaultData);
        $overrides = self::deserializeOverrides(
            isset($decoded['overrides']) && is_array($decoded['overrides'])
                ? json_encode($decoded['overrides'], JSON_THROW_ON_ERROR)
                : '',
        );

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
            if (is_string($key) && $key !== '' && is_array($data)) {
                /** @var array{width: int, offset: int, visible: bool} $data */
                $overrides[$key] = ViewportConfig::fromArray($data);
            }
        }

        /** @var array<non-empty-string, ViewportConfig> $overrides */
        return $overrides;
    }
}
