<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Model\ContentElement;
use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\MappedMediaFields;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Mapping class that converts legacy elemental field structures to the new
 * grid value objects and field names used by BlockMediaExtension.
 *
 * All lookup tables are hardcoded as class constants; constructor injection
 * allows test overrides and project-level customisation without framework
 * hooks. Grid settings are clamped to valid ranges during mapping to prevent
 * invalid legacy data from causing write-time validation failures.
 */
final class FieldMapper
{
    private const array DEFAULT_CLASS_NAME_MAP = [
        'DNADesign\\Elemental\\Models\\ElementContent' => ContentElement::class,
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
     * @param positive-int               $columnCount        Grid column count for clamping (default 12)
     */
    public function __construct(
        ?array $classNameMap = null,
        ?array $verticalAlignMap = null,
        ?array $mediaPositionMap = null,
        ?array $gapSizeMap = null,
        private readonly int $columnCount = 12,
        private readonly ?LoggerInterface $logger = null,
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
        // Size=0 means "not set" (column missing or never configured) — default to full width
        $rawWidth = $element->sizeFields[$defaultViewport] ?? 0;

        $defaultConfig = new ViewportConfig(
            width: $rawWidth > 0 ? $rawWidth : $this->columnCount,
            offset: $element->offsetFields[$defaultViewport] ?? 0,
            visible: $this->mapVisibility($element->visibilityFields[$defaultViewport] ?? null) ?? true,
        );
        $defaultConfig = $this->clampViewportConfig($defaultConfig, $element->id, 'default');

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
            $overrideConfig = $this->clampViewportConfig($overrideConfig, $element->id, $newKey);

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
     */
    public function mapMediaFields(LegacyMediaData $mediaData): MappedMediaFields
    {
        $fields = $mediaData->fields;

        // MediaRatio: '' or null → 'auto'
        /** @var string|null $ratio */
        $ratio = $fields['MediaRatio'] ?? null;

        // ContentVerticalAlign CSS class → enum value
        /** @var string $align */
        $align = $fields['ContentVerticalAlign'] ?? '';

        // MediaPosition CSS class → enum value
        /** @var string $position */
        $position = $fields['MediaPosition'] ?? '';

        // ContentColumns Varchar → int
        /** @var string|null $cols */
        $cols = $fields['ContentColumns'] ?? '';

        // ExtraColumnGap → GapSize using discrete scale mapping
        /** @var int $gap */
        $gap = $fields['ExtraColumnGap'] ?? 0;

        /** @var array<string, string|int|bool|null> $fields */

        return new MappedMediaFields(
            ContentColumns: ($cols === '' || $cols === null) ? 0 : (int) $cols,
            VerticalAlignment: $this->verticalAlignMap[$align] ?? 'top',
            GapSize: $this->gapSizeMap[$gap] ?? 0,
            MediaType: (string) ($fields['MediaType'] ?? ''),
            MediaCaption: (string) ($fields['MediaCaption'] ?? ''),
            MediaImageID: (int) ($fields['MediaImageID'] ?? 0),
            MediaRatio: ($ratio === '' || $ratio === null) ? 'auto' : (string) $ratio,
            MediaPosition: $this->mediaPositionMap[$position] ?? 'first',
            VideoURL: (string) ($fields['MediaVideoFullURL'] ?? ''),
            VideoProvider: (string) ($fields['MediaVideoProvider'] ?? ''),
            VideoHasOverlay: (bool) ($fields['MediaVideoHasOverlay'] ?? false),
            VideoCustomThumbnailID: (int) ($fields['MediaVideoCustomThumbnailID'] ?? 0),
            VideoEmbedName: (string) ($fields['MediaVideoEmbeddedName'] ?? ''),
            VideoEmbedURL: (string) ($fields['MediaVideoEmbeddedURL'] ?? ''),
            VideoEmbedDescription: (string) ($fields['MediaVideoEmbeddedDescription'] ?? ''),
            VideoEmbedThumbnail: (string) ($fields['MediaVideoEmbeddedThumbnail'] ?? ''),
            VideoEmbedCreated: (string) ($fields['MediaVideoEmbeddedCreated'] ?? ''),
        );
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
     * Clamp a ViewportConfig's width and offset to valid ranges.
     *
     * Rules applied in order:
     * 1. width ∈ [1, columnCount]
     * 2. offset ∈ [0, columnCount − 1]
     * 3. width + offset ≤ columnCount (reduce offset if needed)
     */
    private function clampViewportConfig(ViewportConfig $config, int $elementId, string $viewport): ViewportConfig
    {
        $width = \max(1, \min($config->width, $this->columnCount));
        $offset = \max(0, \min($config->offset, $this->columnCount - 1));

        if ($width + $offset > $this->columnCount) {
            $offset = $this->columnCount - $width;
        }

        if ($width !== $config->width || $offset !== $config->offset) {
            $this->logger?->warning(
                'Clamped grid settings for element {elementId} viewport "{viewport}": width {oldWidth}→{newWidth}, offset {oldOffset}→{newOffset}',
                [
                    'elementId' => $elementId,
                    'viewport' => $viewport,
                    'oldWidth' => $config->width,
                    'newWidth' => $width,
                    'oldOffset' => $config->offset,
                    'newOffset' => $offset,
                ],
            );

            return new ViewportConfig($width, $offset, $config->visible);
        }

        return $config;
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
