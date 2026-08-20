<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
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
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [VetoingPermissionExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    /**
     * @param 'canView'|'canEdit'|'canDelete' $method
     */
    #[DataProvider('permissionMethodProvider')]
    public function testCanPermissionHonoursExtensionVeto(string $method): void
    {
        // Admin would otherwise be granted the permission via the owning page.
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertFalse($section->{$method}(), 'extension veto must override the page-level grant');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function permissionMethodProvider(): iterable
    {
        yield 'canView' => ['canView'];
        yield 'canEdit' => ['canEdit'];
        yield 'canDelete' => ['canDelete'];
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
