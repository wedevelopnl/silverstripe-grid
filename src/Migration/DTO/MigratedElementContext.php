<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

use WeDevelop\Grid\Model\GridElement;

/**
 * What a new grid element's `onAfterMigrate` hook is told about the write that
 * just happened, next to the {@see LegacyElement} it was written from.
 *
 * The hook fires on the new element once per stage it is written to, inside
 * the page's transaction.
 */
final readonly class MigratedElementContext
{
    public const string DRAFT = 'draft';

    public const string LIVE = 'live';

    /**
     * @param self::DRAFT|self::LIVE $stage the stage the element was just written to
     * @param int $legacyAreaId the legacy ElementalArea the element was read from
     * @param non-empty-string|null $locale the Fluent locale pass, null without Fluent
     * @param list<int> $sharedWithPageIds the other pages that use the same legacy area; empty when it is not shared
     * @param GridElement|null $firstCopy the element an earlier page or locale pass already wrote for the same
     *     legacy element, which took its owned items; null when this element is the first copy
     */
    public function __construct(
        public string $stage,
        public int $pageId,
        public int $legacyAreaId,
        public ?string $locale,
        public array $sharedWithPageIds,
        public ?GridElement $firstCopy,
    ) {}
}
