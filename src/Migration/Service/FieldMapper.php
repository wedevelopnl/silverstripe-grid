<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Pure mapping class with no framework dependencies.
 *
 * Converts legacy elemental field structures to the new grid value objects
 * and field names used by BlockMediaExtension. All lookup tables are
 * hardcoded as class constants; constructor injection allows test overrides
 * and project-level customisation without framework hooks.
 */
final class FieldMapper
{
    private const array DEFAULT_CLASS_NAME_MAP = [
        'DNADesign\\Elemental\\Models\\ElementContent' => 'WeDevelop\\Grid\\Model\\ContentElement',
    ];

    private const array VERTICAL_ALIGN_MAP = [
        '' => 'top',
        'align-items-center' => 'center',
        'align-items-end' => 'bottom',
    ];

    private const array MEDIA_POSITION_MAP = [
        'order-1' => 'first',
        'order-2' => 'last',
        'order-1 order-md-2' => 'last-on-desktop',
    ];

    private const array GAP_SIZE_MAP = [
        0 => 0,
        2 => 1,
        3 => 1,
        5 => 2,
        7 => 3,
        9 => 3,
        11 => 4,
        16 => 5,
        17 => 5,
    ];

    private const array FIELD_RENAME_MAP = [
        'MediaVideoFullURL' => 'VideoURL',
        'MediaVideoProvider' => 'VideoProvider',
        'MediaVideoHasOverlay' => 'VideoHasOverlay',
        'MediaVideoCustomThumbnailID' => 'VideoCustomThumbnailID',
        'MediaVideoEmbeddedName' => 'VideoEmbedName',
        'MediaVideoEmbeddedURL' => 'VideoEmbedURL',
        'MediaVideoEmbeddedDescription' => 'VideoEmbedDescription',
        'MediaVideoEmbeddedThumbnail' => 'VideoEmbedThumbnail',
        'MediaVideoEmbeddedCreated' => 'VideoEmbedCreated',
    ];

    /** Ordered list of old viewport keys the mapper iterates over for overrides. */
    private const array OLD_VIEWPORTS = ['XS', 'SM', 'MD', 'LG', 'XL'];

    /** @var array<string, string> */
    private array $classNameMap;

    /** @var array<string, string> */
    private array $verticalAlignMap;

    /** @var array<string, string> */
    private array $mediaPositionMap;

    /** @var array<int, int> */
    private array $gapSizeMap;

    /**
     * @param array<string, string>|null $classNameMap       Replaces the default map entirely when provided
     * @param array<string, string>|null $verticalAlignMap   Replaces the default map entirely when provided
     * @param array<string, string>|null $mediaPositionMap   Replaces the default map entirely when provided
     * @param array<int, int>|null       $gapSizeMap         Replaces the default map entirely when provided
     */
    public function __construct(
        ?array $classNameMap = null,
        ?array $verticalAlignMap = null,
        ?array $mediaPositionMap = null,
        ?array $gapSizeMap = null,
    ) {
        $this->classNameMap = $classNameMap ?? self::DEFAULT_CLASS_NAME_MAP;
        $this->verticalAlignMap = $verticalAlignMap ?? self::VERTICAL_ALIGN_MAP;
        $this->mediaPositionMap = $mediaPositionMap ?? self::MEDIA_POSITION_MAP;
        $this->gapSizeMap = $gapSizeMap ?? self::GAP_SIZE_MAP;
    }

    /**
     * Convert flat per-viewport grid fields to a GridSettings value object.
     *
     * The $defaultViewport key identifies which old viewport becomes the
     * GridSettings default. All other viewports are candidates for overrides;
     * a viewport produces an override only when its resolved config differs
     * from the default. Size=0 means "not set" and is skipped unless offset
     * or visibility also differ.
     *
     * @param array<string, string> $viewportKeyMap Old viewport key → new key (e.g. 'XS' → 'xs')
     */
    public function mapGridSettings(LegacyElement $element, string $defaultViewport, array $viewportKeyMap): GridSettings
    {
        $defaultConfig = new ViewportConfig(
            width: $element->sizeFields[$defaultViewport] ?? 12,
            offset: $element->offsetFields[$defaultViewport] ?? 0,
            visible: $this->mapVisibility($element->visibilityFields[$defaultViewport] ?? null) ?? true,
        );

        /** @var array<non-empty-string, ViewportConfig> $overrides */
        $overrides = [];

        foreach (self::OLD_VIEWPORTS as $oldKey) {
            if ($oldKey === $defaultViewport) {
                continue;
            }

            $newKey = $viewportKeyMap[$oldKey] ?? null;
            if ($newKey === null || $newKey === '') {
                continue;
            }

            /** @var non-empty-string $newKey */

            $size = $element->sizeFields[$oldKey] ?? 0;
            $offset = $element->offsetFields[$oldKey] ?? 0;
            $visible = $this->mapVisibility($element->visibilityFields[$oldKey] ?? null);

            // Size=0 with no offset and no explicit visibility means the field was never set
            if ($size === 0 && $offset === 0 && $visible === null) {
                continue;
            }

            $overrideConfig = new ViewportConfig(
                width: $size > 0 ? $size : $defaultConfig->width,
                offset: $offset,
                visible: $visible ?? $defaultConfig->visible,
            );

            if (!$overrideConfig->equals($defaultConfig)) {
                $overrides[$newKey] = $overrideConfig;
            }
        }

        return new GridSettings($defaultConfig, $overrides);
    }

    /**
     * Map old ElementContentExtension fields to new BlockMediaExtension fields.
     *
     * Handles field renames, value transformations (CSS class → enum string,
     * scale mapping, string→int coercion), and null/empty normalisation.
     *
     * @return array<string, mixed>
     */
    public function mapMediaFields(LegacyMediaData $mediaData): array
    {
        $fields = $mediaData->fields;
        $mapped = [];

        // Renamed fields — old names are dropped, new names take their values
        foreach (self::FIELD_RENAME_MAP as $old => $new) {
            if (\array_key_exists($old, $fields)) {
                $mapped[$new] = $fields[$old];
            }
        }

        // Pass-through fields (same name in old and new)
        foreach (['MediaType', 'MediaCaption', 'MediaImageID', 'MediaRatio'] as $field) {
            if (\array_key_exists($field, $fields)) {
                $mapped[$field] = $fields[$field];
            }
        }

        // MediaRatio: '' or null → 'auto'
        $ratio = $mapped['MediaRatio'] ?? null;
        $mapped['MediaRatio'] = ($ratio === '' || $ratio === null) ? 'auto' : $ratio;

        // ContentVerticalAlign CSS class → enum value; missing/unknown keys fall back to 'top'
        /** @var string $align */
        $align = $fields['ContentVerticalAlign'] ?? '';
        $mapped['VerticalAlignment'] = $this->verticalAlignMap[$align] ?? 'top';

        // MediaPosition CSS class → enum value; null/empty fall back to 'first'
        /** @var string $position */
        $position = $fields['MediaPosition'] ?? '';
        $mapped['MediaPosition'] = $this->mediaPositionMap[$position] ?? 'first';

        // ContentColumns Varchar → int; '' or null → 0
        /** @var string|null $cols */
        $cols = $fields['ContentColumns'] ?? '';
        $mapped['ContentColumns'] = ($cols === '' || $cols === null) ? 0 : (int) $cols;

        // ExtraColumnGap → GapSize using a discrete scale mapping
        /** @var int $gap */
        $gap = $fields['ExtraColumnGap'] ?? 0;
        $mapped['GapSize'] = $this->gapSizeMap[$gap] ?? 0;

        return $mapped;
    }

    /**
     * Resolve an old ClassName to its new equivalent.
     *
     * Returns the original class name unchanged when no mapping is configured.
     */
    public function resolveClassName(string $oldClassName): string
    {
        return $this->classNameMap[$oldClassName] ?? $oldClassName;
    }

    /**
     * Map a visibility string to a boolean, or null when the field is unset.
     *
     * Null and empty string both represent "not explicitly configured" and
     * allow the caller to fall back to a default.
     */
    private function mapVisibility(?string $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value === 'visible';
    }
}
