<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Model\ArrayData;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\AdminToolbar\ToolbarContext;
use WeDevelop\Grid\AdminToolbar\GridMenu;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(GridMenu::class)]
final class FluentGridMenuTest extends FluentGridTestCase
{
    public function testMenuListsOnlyTheCurrentLocalesElements(): void
    {
        $page = $this->createPage();
        GridTreeFactory::section($page, title: 'EN Section');
        FluentState::singleton()->withState(static function (FluentState $state) use ($page): void {
            $state->setLocale('nl_NL');
            GridTreeFactory::section($page, title: 'NL Section');
        });

        self::assertSame(['EN Section'], $this->mainTitles($page));

        FluentState::singleton()->setLocale('nl_NL');

        self::assertSame(['NL Section'], $this->mainTitles($page));
    }

    /** @return list<mixed> */
    private function mainTitles(SiteTree $page): array
    {
        $member = $this->createMemberWithPermission('ADMIN');
        $menu = GridMenu::create()->setContext(new ToolbarContext($page, $member, new HTTPRequest('GET', '/')));
        $main = $menu->getZones()->find('Name', 'main');
        self::assertInstanceOf(ArrayData::class, $main);

        return $main->Nodes->column('Title');
    }
}
