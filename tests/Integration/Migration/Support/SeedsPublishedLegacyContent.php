<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use Page;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * Seeds published SilverStripe 5 text blocks (same row on draft and live) and
 * runs the migration task over them, for {@see MigrationTestCase} subclasses.
 */
trait SeedsPublishedLegacyContent
{
    /**
     * Seed the legacy area and publish the page, as a SilverStripe 5 site
     * stores a published page.
     *
     * @param non-empty-string $handle fixture handle
     */
    private function seedPublishedPage(string $handle, int $areaId): void
    {
        $this->seeder->seedPage($this->pageId($handle), $areaId);
        $page = $this->objFromFixture(Page::class, $handle);
        $page->publishSingle();
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 1, "ElementalAreaID" = ? WHERE "ID" = ?',
            [$areaId, $page->ID],
        );
    }

    /**
     * @param array<string, mixed> $media legacy ElementContent column values
     */
    private function seedPublishedBlock(int $areaId, int $elementId, int $sort, string $html, array $media = []): void
    {
        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement($elementId, $areaId, self::CONTENT_CLASS, $sort, ['Title' => "Block {$elementId}"], $stage);
            $this->seeder->seedContentMedia($elementId, ['HTML' => $html, ...$media], $stage);
        }
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    private function migrate(): array
    {
        return TaskRunner::run(new MigrateGridTask(), [
            '--default-viewport' => self::DEFAULT_VIEWPORT,
            '--zone' => self::ZONE,
            ...TaskRunner::ACKNOWLEDGED,
        ]);
    }

    /**
     * @return list<ContentElement> every migrated text block on a stage, in creation order
     */
    private function migratedBlocks(string $stage): array
    {
        return Versioned::withVersionedMode(static function () use ($stage): array {
            Versioned::set_stage($stage);

            return \array_values(ContentElement::get()->sort('ID')->toArray());
        });
    }
}
