<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;
use Override;

/**
 * The shared-block facts a reference node carries on the wire: enough for the
 * editor to draw its frame, chip and status badge without a second request.
 *
 * @phpstan-type SerializedSharedBlockMeta array{blockId: positive-int, title: string, usageCount: int<0, max>, status: value-of<SharedBlockStatus>}
 */
final readonly class SharedBlockMeta implements JsonSerializable
{
    /**
     * @param positive-int $blockId
     * @param int<0, max> $usageCount Distinct pages placing this block, not placements.
     */
    public function __construct(
        public int $blockId,
        public string $title,
        public int $usageCount,
        public SharedBlockStatus $status,
    ) {
    }

    /** @return SerializedSharedBlockMeta */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'blockId' => $this->blockId,
            'title' => $this->title,
            'usageCount' => $this->usageCount,
            'status' => $this->status->value,
        ];
    }
}
