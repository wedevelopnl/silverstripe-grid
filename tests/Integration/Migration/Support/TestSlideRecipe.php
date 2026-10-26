<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigratedElementContext;
use WeDevelop\Grid\Model\GridElement;

/**
 * The re-attach recipe of docs/migration.md, applied to the migrated block:
 * the first copy takes the legacy block's slides, every later copy gets
 * copies of the first copy's slides, stage by stage.
 *
 * @extends Extension<GridElement>
 */
final class TestSlideRecipe extends Extension implements TestOnly
{
    public function onAfterMigrate(LegacyElement $legacy, MigratedElementContext $context): void
    {
        $slider = $this->getOwner();
        $firstCopy = $context->firstCopy;

        if ($firstCopy === null) {
            // Re-point the legacy block's slides on the stage just written.
            $table = DataObject::getSchema()->tableName(Slide::class);
            if ($context->stage === MigratedElementContext::LIVE) {
                $table .= '_Live';
            }

            DB::prepared_query(
                \sprintf('UPDATE "%s" SET "SliderID" = ? WHERE "SliderID" = ?', $table),
                [$slider->ID, $legacy->id],
            );

            return;
        }

        // A later copy copies the first copy's slides once, on the draft call,
        // each stage from that stage's row: draft may hold unpublished edits,
        // and a slide may be on one stage only.
        if ($context->stage !== MigratedElementContext::DRAFT) {
            return;
        }

        $slides = [
            Versioned::LIVE => Versioned::get_by_stage(Slide::class, Versioned::LIVE)->filter('SliderID', $firstCopy->ID),
            Versioned::DRAFT => Versioned::get_by_stage(Slide::class, Versioned::DRAFT)->filter('SliderID', $firstCopy->ID),
        ];
        $slideIds = \array_unique([...$slides[Versioned::LIVE]->column('ID'), ...$slides[Versioned::DRAFT]->column('ID')]);
        foreach ($slideIds as $slideId) {
            $copy = null;
            // Live first: a write to live writes draft too.
            foreach ($slides as $stage => $stageSlides) {
                $slide = $stageSlides->byID($slideId);
                if ($slide === null) {
                    continue;
                }

                $copyId = $copy?->ID ?? 0;
                $copy = $slide->duplicate(false);
                $copy->ID = $copyId;
                $copy->SliderID = $slider->ID;
                $copy->writeToStage($stage);
            }

            if ($copy !== null && $slides[Versioned::DRAFT]->byID($slideId) === null) {
                $copy->deleteFromStage(Versioned::DRAFT);
            }
        }
    }
}
