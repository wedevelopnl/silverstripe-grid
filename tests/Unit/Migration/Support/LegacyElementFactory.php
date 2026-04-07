<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Support;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;

/**
 * Builds LegacyElement instances with sensible defaults for unit tests.
 *
 * Only specify the fields relevant to the test case — all others get
 * safe, non-null defaults so tests don't have to repeat boilerplate.
 */
final class LegacyElementFactory
{
    /**
     * Builds a non-row content element with optional field overrides.
     *
     * @param array<string, mixed> $overrides Any LegacyElement constructor field by name
     */
    public static function content(int $id = 1, int $sort = 1, array $overrides = []): LegacyElement
    {
        return new LegacyElement(
            id: $overrides['id'] ?? $id,
            className: $overrides['className'] ?? 'Test\Element',
            title: $overrides['title'] ?? 'Test Element',
            showTitle: $overrides['showTitle'] ?? false,
            titleTag: $overrides['titleTag'] ?? 'h2',
            titleClass: $overrides['titleClass'] ?? '',
            sort: $overrides['sort'] ?? $sort,
            extraClass: $overrides['extraClass'] ?? '',
            isRow: false,
            sizeFields: $overrides['sizeFields'] ?? [],
            offsetFields: $overrides['offsetFields'] ?? [],
            visibilityFields: $overrides['visibilityFields'] ?? [],
            rowData: $overrides['rowData'] ?? null,
            mediaData: $overrides['mediaData'] ?? null,
            extraData: $overrides['extraData'] ?? [],
        );
    }

    /**
     * Builds a legacy row element (isRow = true).
     */
    public static function row(int $id = 1, int $sort = 1, ?LegacyRowData $rowData = null): LegacyElement
    {
        return new LegacyElement(
            id: $id,
            className: 'Test\Row',
            title: 'Test Row',
            showTitle: false,
            titleTag: 'h2',
            titleClass: '',
            sort: $sort,
            extraClass: '',
            isRow: true,
            sizeFields: [],
            offsetFields: [],
            visibilityFields: [],
            rowData: $rowData ?? new LegacyRowData(customSectionClass: ''),
            mediaData: null,
            extraData: [],
        );
    }
}
