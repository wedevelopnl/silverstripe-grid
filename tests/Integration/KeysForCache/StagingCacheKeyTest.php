<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\KeysForCache;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use Terraformers\KeysForCache\Services\ProcessedUpdatesService;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Section;

/**
 * Guards that draft-only edits do not change the live cache key until publish.
 */
#[CoversNothing]
final class StagingCacheKeyTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    private function liveSectionKey(int $sectionId): ?string
    {
        return Versioned::withVersionedMode(function () use ($sectionId): ?string {
            Versioned::set_stage(Versioned::LIVE);
            $section = Section::get()->byID($sectionId);

            return $section?->getCacheKey();
        });
    }

    public function testDraftEditDoesNotChangeLiveKeyUntilPublish(): void
    {
        $page = Page::create();
        $page->Title = 'Staging';
        $page->URLSegment = 'kfc-staging';
        $page->write();

        $section = Section::create();
        $section->Title = 'Section';
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        /** @var Column $column */
        $column = $section->getChildren()->first()->getChildren()->first();

        // Publish the whole owned tree to LIVE.
        $page->publishRecursive();

        $liveKeyBefore = $this->liveSectionKey($section->ID);
        self::assertNotNull($liveKeyBefore);

        // Draft-only edit: add a leaf element, do NOT publish.
        ProcessedUpdatesService::singleton()->flush();
        $element = ContentElement::create();
        $element->Title = 'Draft leaf';
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        $liveKeyAfterDraft = $this->liveSectionKey($section->ID);
        self::assertSame($liveKeyBefore, $liveKeyAfterDraft, 'Live key must be stable across draft-only edits');

        // Publish the new element; live key must now change.
        ProcessedUpdatesService::singleton()->flush();
        $element->publishRecursive();

        $liveKeyAfterPublish = $this->liveSectionKey($section->ID);
        self::assertNotSame($liveKeyBefore, $liveKeyAfterPublish, 'Live key must change after publish');
    }
}
