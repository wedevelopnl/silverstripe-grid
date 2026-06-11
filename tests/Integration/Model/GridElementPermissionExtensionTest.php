<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\VetoingPermissionExtension;

/**
 * Proves {@see GridElement} routes every can* permission check through
 * {@see \SilverStripe\ORM\DataObject::extendedCan()} — an extension that
 * vetoes (returns false) must be honoured before the page/Permission fallback,
 * even when the page would otherwise grant the permission.
 */
#[CoversClass(GridElement::class)]
final class GridElementPermissionExtensionTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [VetoingPermissionExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    public function testCanViewHonoursExtensionVeto(): void
    {
        // Admin would otherwise be granted view via the owning page.
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertFalse($section->canView(), 'extension veto must override the page-level grant');
    }

    public function testCanEditHonoursExtensionVeto(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertFalse($section->canEdit(), 'extension veto must override the page-level grant');
    }

    public function testCanDeleteHonoursExtensionVeto(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertFalse($section->canDelete(), 'extension veto must override the page-level grant');
    }

    public function testCanCreateHonoursExtensionVeto(): void
    {
        $this->logInWithPermission('ADMIN');

        self::assertFalse(
            ContentElement::singleton()->canCreate(),
            'extension veto must override the CMS_ACCESS grant',
        );
    }
}
