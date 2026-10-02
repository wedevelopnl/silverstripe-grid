<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Service\GridZoneResolver;
use WeDevelop\Grid\Tests\Integration\Support\MultiZoneTestPage;

#[CoversClass(GridZoneResolver::class)]
final class GridZoneResolverTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string> */
    protected static $extra_dataobjects = [
        MultiZoneTestPage::class,
    ];

    private function resolver(): GridZoneResolver
    {
        return GridZoneResolver::singleton();
    }

    public function testAStandardGridPageHasTheMainZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        self::assertSame(['main'], $this->resolver()->zonesFor($page));
    }

    public function testEveryGridEditorFieldContributesItsZoneInFormOrder(): void
    {
        $page = MultiZoneTestPage::create();
        $page->Title = 'Two zones';
        $page->write();

        self::assertSame(['main', 'sidebar'], $this->resolver()->zonesFor($page));
    }

    public function testAPageWithTheGridSwitchedOffHasNoZones(): void
    {
        Page::config()->set('enable_editor_toggle', true);

        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->UseGrid = false;

        self::assertSame([], $this->resolver()->zonesFor($page));
    }
}
