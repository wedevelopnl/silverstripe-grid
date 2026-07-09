<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Writes the page-level UseGrid flag on the draft and/or live page table and
 * reconciles grid-disabled legacy pages.
 */
final readonly class PageGridFlagWriter
{
    public function __construct(
        private LegacyElementSource $reader,
        private LoggerInterface $logger,
    ) {}

    /**
     * Uses raw SQL for consistency with the migration's existing approach
     * to live-stage table updates. The table is resolved dynamically because
     * the UseGrid column lives on whichever page class has GridPageExtension
     * applied (e.g. Page, not necessarily SiteTree).
     *
     * @param bool $includeDraft Update draft table
     * @param bool $includeLive Update live (_Live) table
     */
    public function setUseGridOnPage(int $pageId, bool $enabled, bool $includeDraft = true, bool $includeLive = false): void
    {
        $table = $this->resolveUseGridTable();
        if ($table === null) {
            return;
        }

        $value = $enabled ? 1 : 0;

        if ($includeDraft) {
            DB::prepared_query(
                \sprintf('UPDATE "%s" SET "UseGrid" = ? WHERE "ID" = ?', $table),
                [$value, $pageId],
            );
        }

        if ($includeLive) {
            DB::prepared_query(
                \sprintf('UPDATE "%s_Live" SET "UseGrid" = ? WHERE "ID" = ?', $table),
                [$value, $pageId],
            );
        }
    }

    /**
     * Preserve UseGrid = 0 for pages that had UseElementalGrid disabled.
     *
     * These pages are excluded from content migration (no grid content to move)
     * but their toggle state must be carried forward so the Content editor
     * remains active after migration.
     */
    public function migrateDisabledGridPages(): void
    {
        $draftPages = $this->reader->getPagesWithGridDisabled('draft');
        foreach ($draftPages as $pageInfo) {
            $this->setUseGridOnPage($pageInfo['pageId'], false);
        }

        $livePages = $this->reader->getPagesWithGridDisabled('live');
        foreach ($livePages as $pageInfo) {
            $this->setUseGridOnPage($pageInfo['pageId'], false, includeDraft: false, includeLive: true);
        }

        $totalPages = \count($draftPages) + \count($livePages);
        if ($totalPages > 0) {
            $this->logger->info('Set UseGrid = 0 for {draftCount} draft and {liveCount} live page(s) with grid disabled.', [
                'draftCount' => \count($draftPages),
                'liveCount' => \count($livePages),
            ]);
        }
    }

    /**
     * Find which table in the SiteTree hierarchy stores the UseGrid column.
     *
     * GridPageExtension can be applied to any page class (Page, a custom
     * subclass, etc.), so the table is not known at compile time.
     *
     * @return non-empty-string|null Table name, or null if no page class has the column
     */
    private function resolveUseGridTable(): ?string
    {
        $schema = DataObject::getSchema();

        foreach (ClassInfo::subclassesFor(SiteTree::class, true) as $class) {
            $fieldClass = $schema->classForField($class, 'UseGrid');
            if ($fieldClass !== null) {
                /** @var non-empty-string */
                return $schema->tableName($fieldClass);
            }
        }

        return null;
    }
}
