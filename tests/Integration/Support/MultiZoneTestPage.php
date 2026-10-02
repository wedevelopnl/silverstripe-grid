<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use Override;
use Page;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\FieldList;
use WeDevelop\Grid\Forms\GridEditorField;

/**
 * Test-only page exposing two grid zones (main + sidebar), standing in for a
 * project page type with more than one grid area.
 *
 * The harness's App\MultiZonePage exists only in the dev app, not in the test
 * manifest a consumer runs, so the PHPUnit suites carry their own.
 */
class MultiZoneTestPage extends Page implements TestOnly
{
    private static string $table_name = 'WeDevelop_Grid_Test_MultiZonePage';

    #[Override]
    public function getCMSFields(): FieldList
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields): void {
            $fields->addFieldToTab('Root.Main', GridEditorField::create('GridEditorMain', (int) $this->ID, 'main'));
            $fields->addFieldToTab('Root.Main', GridEditorField::create('GridEditorSidebar', (int) $this->ID, 'sidebar'));
        });

        // GridPageExtension adds its single-zone "GridEditor" during the
        // extension chain; strip it so only the two editors above remain.
        $this->afterUpdateCMSFields(function (FieldList $fields): void {
            $fields->removeByName('GridEditor');
        });

        return parent::getCMSFields();
    }
}
