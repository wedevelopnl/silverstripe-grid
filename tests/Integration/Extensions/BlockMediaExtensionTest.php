<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;

/**
 * Integration tests for BlockMediaExtension applied to ContentElement.
 *
 * Covers framework lifecycle: extension wiring, CMS fields, DB persistence.
 */
#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    // --- Extension wiring ---

    public function testExtensionIsAppliedToContentElement(): void
    {
        $element = ContentElement::create();

        $this->assertTrue($element->hasExtension(BlockMediaExtension::class));
    }

    public function testDefaultsAreApplied(): void
    {
        $element = ContentElement::create();
        $element->write();

        $this->assertSame('first', $element->MediaPosition);
        $this->assertSame('center', $element->VerticalAlignment);
        $this->assertSame('auto', $element->MediaRatio);
    }

    // --- CMS fields ---

    public function testCMSFieldsIncludeMediaAndLayoutTabs(): void
    {
        $element = ContentElement::create();
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNotNull($fields->findTab('Root.Media'), 'Media tab should exist');
        $this->assertNotNull($fields->findTab('Root.Layout'), 'Layout tab should exist');
    }

    public function testCMSFieldsIncludeLayoutControls(): void
    {
        $element = ContentElement::create();
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNotNull($fields->dataFieldByName('ContentColumns'));
        $this->assertNotNull($fields->dataFieldByName('MediaPosition'));
        $this->assertNotNull($fields->dataFieldByName('VerticalAlignment'));
        $this->assertNotNull($fields->dataFieldByName('GapSize'));
    }

    public function testVideoEmbedTabNotShownWithoutEmbedData(): void
    {
        $element = ContentElement::create();
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNull($fields->findTab('Root.VideoEmbed'));
    }

    public function testVideoEmbedTabShownWithEmbedData(): void
    {
        $element = ContentElement::create();
        $element->VideoEmbedName = 'Test Video';
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNotNull($fields->findTab('Root.VideoEmbed'));
    }

    // --- Write cycle ---

    public function testWritePersistsMediaFields(): void
    {
        $element = ContentElement::create();
        $element->MediaType = 'video';
        $element->VideoURL = 'https://example.com/video';
        $element->ContentColumns = 8;
        $element->MediaPosition = 'last-on-desktop';
        $element->VerticalAlignment = 'top';
        $element->GapSize = 3;
        $element->MediaRatio = '16x9';
        $element->write();

        $reloaded = ContentElement::get()->byID($element->ID);
        $this->assertNotNull($reloaded);

        $this->assertSame('video', $reloaded->MediaType);
        $this->assertSame('https://example.com/video', $reloaded->VideoURL);
        $this->assertSame(8, (int) $reloaded->ContentColumns);
        $this->assertSame('last-on-desktop', $reloaded->MediaPosition);
        $this->assertSame('top', $reloaded->VerticalAlignment);
        $this->assertSame(3, (int) $reloaded->GapSize);
        $this->assertSame('16x9', $reloaded->MediaRatio);
    }

    public function testOnBeforeWriteTrimsVideoURL(): void
    {
        $element = ContentElement::create();
        $element->VideoURL = '  https://example.com/video  ';
        $element->write();

        $this->assertSame('https://example.com/video', $element->VideoURL);
    }
}
