<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigratedElementContext;
use WeDevelop\Grid\Model\GridElement;

/**
 * The re-attach recipe of docs/migration.md, applied to the migrated block:
 * the first copy takes the legacy block's slides, every later copy gets
 * duplicates of the first copy's slides.
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

        // A later copy duplicates the first copy's slides once, on the draft call.
        if ($context->stage === MigratedElementContext::DRAFT) {
            foreach (Slide::get()->filter('SliderID', $firstCopy->ID) as $slide) {
                $copy = $slide->duplicate(false);
                $copy->SliderID = $slider->ID;
                $copy->write();
                if ($slide->isPublished()) {
                    $copy->publishSingle();
                }
            }
        }
    }
}
