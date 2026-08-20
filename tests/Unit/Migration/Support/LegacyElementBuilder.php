<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Support;

use WeDevelop\Grid\Migration\DTO\LegacyElement;

/**
 * Sequential-id builder for the strategy hierarchy tests.
 *
 * Ids (and matching sorts) increment per instance, so each provider case
 * creates a fresh builder and its elements are numbered from 1 in
 * declaration order.
 */
final class LegacyElementBuilder
{
    private int $nextId = 0;

    /**
     * Content element with only default-viewport grid settings.
     *
     * @param array<string, int> $sizeFields Override size fields (default: ['MD' => $width])
     * @param array<string, int> $offsetFields Override offset fields (default: ['MD' => $offset])
     * @param array<string, ?string> $visibilityFields Override visibility fields
     */
    public function e(
        int $width,
        int $offset = 0,
        array $sizeFields = [],
        array $offsetFields = [],
        array $visibilityFields = [],
    ): LegacyElement {
        $id = ++$this->nextId;

        return LegacyElementFactory::content($id, $id, [
            'sizeFields' => $sizeFields !== [] ? $sizeFields : ['MD' => $width],
            'offsetFields' => $offsetFields !== [] ? $offsetFields : ['MD' => $offset],
            'visibilityFields' => $visibilityFields,
        ]);
    }

    /** Row delimiter element (isRow = true) with an empty title by default. */
    public function r(string $title = '', string $extraClass = '', string $sectionClass = ''): LegacyElement
    {
        $id = ++$this->nextId;

        return LegacyElementFactory::row($id, $id, null, [
            'title' => $title,
            'extraClass' => $extraClass,
            'sectionClass' => $sectionClass,
        ]);
    }
}
