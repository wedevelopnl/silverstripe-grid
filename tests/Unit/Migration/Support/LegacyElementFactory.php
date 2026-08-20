<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Support;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;

/**
 * Builds LegacyElement instances with sensible defaults for tests.
 *
 * Only specify the fields relevant to the test case — all others get
 * safe, non-null defaults so tests don't have to repeat boilerplate.
 * `id` and `sort` are positional-only; the override maps cover the
 * remaining constructor fields tests actually vary.
 */
final class LegacyElementFactory
{
    /**
     * Builds a non-row content element with optional field overrides.
     *
     * @param array<string, mixed> $overrides LegacyElement constructor fields by name
     *                                        (className, title, showTitle, titleTag, titleClass,
     *                                        extraClass, sizeFields, offsetFields, visibilityFields,
     *                                        mediaData, extraData)
     */
    public static function content(int $id = 1, int $sort = 1, array $overrides = []): LegacyElement
    {
        return new LegacyElement(
            id: $id,
            className: $overrides['className'] ?? 'Test\Element',
            title: $overrides['title'] ?? 'Test Element',
            showTitle: $overrides['showTitle'] ?? false,
            titleTag: $overrides['titleTag'] ?? 'h2',
            titleClass: $overrides['titleClass'] ?? '',
            sort: $sort,
            extraClass: $overrides['extraClass'] ?? '',
            isRow: false,
            sizeFields: $overrides['sizeFields'] ?? [],
            offsetFields: $overrides['offsetFields'] ?? [],
            visibilityFields: $overrides['visibilityFields'] ?? [],
            rowData: null,
            mediaData: $overrides['mediaData'] ?? null,
            extraData: $overrides['extraData'] ?? [],
        );
    }

    /**
     * Builds a legacy row element (isRow = true) with optional field overrides.
     *
     * @param array<string, mixed> $overrides Same keys as content(), plus 'sectionClass'
     *                                        (sugar for rowData's customSectionClass; ignored
     *                                        when an explicit $rowData is passed)
     */
    public static function row(int $id = 1, int $sort = 1, ?LegacyRowData $rowData = null, array $overrides = []): LegacyElement
    {
        return new LegacyElement(
            id: $id,
            className: $overrides['className'] ?? 'Test\Row',
            title: $overrides['title'] ?? 'Test Row',
            showTitle: $overrides['showTitle'] ?? false,
            titleTag: $overrides['titleTag'] ?? 'h2',
            titleClass: $overrides['titleClass'] ?? '',
            sort: $sort,
            extraClass: $overrides['extraClass'] ?? '',
            isRow: true,
            sizeFields: $overrides['sizeFields'] ?? [],
            offsetFields: $overrides['offsetFields'] ?? [],
            visibilityFields: $overrides['visibilityFields'] ?? [],
            rowData: $rowData ?? new LegacyRowData(customSectionClass: $overrides['sectionClass'] ?? ''),
            mediaData: $overrides['mediaData'] ?? null,
            extraData: $overrides['extraData'] ?? [],
        );
    }
}
