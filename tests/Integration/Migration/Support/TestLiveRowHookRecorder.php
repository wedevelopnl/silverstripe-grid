<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Records each live row and section hook call, so a test can see one did NOT fire.
 *
 * @extends Extension<\WeDevelop\Grid\Migration\Service\LivePublisher>
 */
final class TestLiveRowHookRecorder extends Extension implements TestOnly
{
    /** @var list<string> one `<Class>:<ID>` entry per fired hook */
    public static array $fired = [];

    public function updateLiveRowFieldMapping(Row $row, LegacyElement $liveRow): void
    {
        self::$fired[] = 'Row:' . $row->ID;
    }

    public function updateLiveSectionFieldMapping(Section $section, LegacyElement $liveRow): void
    {
        self::$fired[] = 'Section:' . $section->ID;
    }
}
