<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

/**
 * What one migration pass converted. In a dry run the counts are what the real
 * run would convert.
 */
final readonly class MigrationSummary
{
    /**
     * @param int<0, max> $pagesMigrated
     * @param int<0, max> $blocksMigrated Legacy elements of the migrated pages that are not
     *     rows (rows become the Section/Row/Column structure, not blocks)
     * @param int<0, max> $pagesSkipped Pages the idempotency guard skipped as already migrated
     * @param int<0, max> $pagesFailed
     * @param list<SkippedPage> $skippedByType Pages left without a grid because their current
     *     type no longer carries their legacy content blocks; never counted as failed
     */
    public function __construct(
        public int $pagesMigrated,
        public int $blocksMigrated,
        public int $pagesSkipped,
        public int $pagesFailed,
        public array $skippedByType = [],
    ) {}
}
