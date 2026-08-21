<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Aggregate publication state of a shared block, driving the editor's
 * pending-changes badge.
 *
 * A block is only as published as its least published part: the block record
 * and every element in its subtree must be in sync before the frame stops
 * advertising unpublished work.
 */
enum SharedBlockStatus: string
{
    case NotPublished = 'notPublished';
    case Modified = 'modified';
    case Published = 'published';

    /**
     * Reader-facing name of this state, for the CMS report's Status column.
     *
     * The wire value ('notPublished') is an API contract the editor matches on
     * and must never be shown to an author.
     *
     * @return non-empty-string
     */
    public function label(): string
    {
        /** @var non-empty-string $label Every arm's default is a non-empty literal. */
        $label = match ($this) {
            self::NotPublished => _t(self::class . '.NOT_PUBLISHED', 'Not published'),
            self::Modified => _t(self::class . '.MODIFIED', 'Unpublished changes'),
            self::Published => _t(self::class . '.PUBLISHED', 'Published'),
        };

        return $label;
    }

    /**
     * Deliberately takes the two versioning facts rather than the SharedBlock
     * itself: `isPublished()`/`stagesDiffer()` arrive through the Versioned
     * extension's method forwarding, so a value object depending on them would
     * drag the ORM into what is a pure decision.
     *
     * @param list<ElementStatus> $subtreeStatuses Status of every element in the block's subtree.
     */
    public static function compute(bool $blockIsPublished, bool $blockStagesDiffer, array $subtreeStatuses): self
    {
        if (!$blockIsPublished) {
            return self::NotPublished;
        }

        if ($blockStagesDiffer) {
            return self::Modified;
        }

        foreach ($subtreeStatuses as $status) {
            if ($status !== ElementStatus::Published) {
                return self::Modified;
            }
        }

        return self::Published;
    }
}
