<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class ResetGridSettingsOverridesRequest
{
    /**
     * @param positive-int $pageId
     * @param non-empty-string $zone
     * @param non-empty-string|null $viewport Null means reset all viewports.
     */
    public function __construct(
        public int $pageId,
        public string $zone,
        public ?string $viewport,
    ) {
    }
}
