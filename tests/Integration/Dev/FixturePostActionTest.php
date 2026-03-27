<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Dev\FixturePostAction;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\UnversionedDataObject;

#[CoversClass(FixturePostAction::class)]
final class FixturePostActionTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        UnversionedDataObject::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    public function testApplyPublishRecursive(): void
    {
        $page = $this->objFromFixture(\SilverStripe\CMS\Model\SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $action = new FixturePostAction('publish_recursive', Section::class, 'unused_identifier');
        $action->apply($section);

        Versioned::set_stage(Versioned::LIVE);
        self::assertNotNull(Section::get()->byID($section->ID));
    }

    public function testApplyUnpublish(): void
    {
        $page = $this->objFromFixture(\SilverStripe\CMS\Model\SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $section->publishRecursive();

        $action = new FixturePostAction('unpublish', Section::class, 'unused');
        $action->apply($section);

        Versioned::set_stage(Versioned::LIVE);
        self::assertNull(Section::get()->byID($section->ID));
    }

    public function testApplyModify(): void
    {
        $page = $this->objFromFixture(\SilverStripe\CMS\Model\SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'Original');

        // Two fields to kill ArrayItemRemoval on the foreach iteration
        $action = new FixturePostAction('modify', Section::class, 'unused', fields: [
            'Title' => 'Modified',
            'Zone' => 'sidebar',
        ]);
        $action->apply($section);

        $reloaded = Section::get()->byID($section->ID);
        self::assertNotNull($reloaded);
        self::assertSame('Modified', $reloaded->Title);
        self::assertSame('sidebar', $reloaded->Zone);
    }

    public function testApplyAttachImageThrowsWithoutRequiredFields(): void
    {
        $page = $this->objFromFixture(\SilverStripe\CMS\Model\SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $action = new FixturePostAction('attach_image', ContentElement::class, 'unused', fields: []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_image requires "relation" and "source" in fields');
        $action->apply($element);
    }

    public function testApplyAttachImageThrowsWithMissingSource(): void
    {
        $page = $this->objFromFixture(\SilverStripe\CMS\Model\SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $action = new FixturePostAction('attach_image', ContentElement::class, 'unused', fields: [
            'relation' => 'MediaImage',
            'source' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attach_image requires "relation" and "source" in fields');
        $action->apply($element);
    }

    public function testApplyThrowsForNonVersionedRecord(): void
    {
        $record = UnversionedDataObject::create();
        $record->Title = 'Test';
        $record->write();

        $action = new FixturePostAction('publish_recursive', UnversionedDataObject::class, 'unused');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires Versioned extension');
        $action->apply($record);
    }

    public function testApplyModifySkipsVersionedCheck(): void
    {
        $record = UnversionedDataObject::create();
        $record->Title = 'Original';
        $record->write();

        $action = new FixturePostAction('modify', UnversionedDataObject::class, 'unused', fields: [
            'Title' => 'Modified',
        ]);
        $action->apply($record);

        $reloaded = UnversionedDataObject::get()->byID($record->ID);
        self::assertNotNull($reloaded);
        self::assertSame('Modified', $reloaded->Title);
    }

    public function testApplyAttachImageThrowsForUnresolvablePath(): void
    {
        $page = $this->objFromFixture(\SilverStripe\CMS\Model\SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $action = new FixturePostAction('attach_image', ContentElement::class, 'unused', fields: [
            'relation' => 'MediaImage',
            'source' => 'nonexistent/module:fake/path.png',
        ]);

        // ModuleResourceLoader throws for unknown modules before resolvePath returns null
        $this->expectException(\Exception::class);
        $action->apply($element);
    }

    public function testApplyAttachImageCreatesAndAttachesImage(): void
    {
        $page = $this->objFromFixture(\SilverStripe\CMS\Model\SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $action = new FixturePostAction('attach_image', ContentElement::class, 'unused', fields: [
            'relation' => 'MediaImage',
            'source' => 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/assets/test-image.png',
        ]);
        $action->apply($element);

        $reloaded = ContentElement::get()->byID($element->ID);
        self::assertNotNull($reloaded);
        self::assertGreaterThan(0, (int) $reloaded->MediaImageID);

        $image = Image::get()->byID((int) $reloaded->MediaImageID);
        self::assertNotNull($image);
    }
}
