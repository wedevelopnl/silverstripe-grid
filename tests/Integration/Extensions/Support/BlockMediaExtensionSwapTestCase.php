<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions\Support;

use Page;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Base for the test classes that replace BlockMediaExtension on ContentElement
 * with a test-only subclass.
 *
 * The swap MUST go through $illegal_extensions/$required_extensions rather than
 * Config::modify()->remove()/merge() on the `extensions` key. Extensible caches
 * one closure per extension method in the class-static CustomMethods::$extra_methods,
 * and each closure captures the extension class name it was built from. Only
 * add_extension()/remove_extension() invalidate that cache — a raw Config write
 * does not. Swapping via Config therefore rebinds every BlockMediaExtension
 * method (the subclasses inherit them all) to the test-only class for the rest
 * of the PHP process; once Config is unnested at tearDown the closure can no
 * longer find its extension and returns null instead of calling the method.
 * That surfaces as unrelated BlockMediaExtension tests failing on `null` under
 * some random test orders and passing under others.
 */
abstract class BlockMediaExtensionSwapTestCase extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    protected function createContentElement(): ContentElement
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        return GridTreeFactory::contentElement($column, title: 'Media Test');
    }
}
