<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;

#[CoversClass(ContentElement::class)]
final class ContentElementTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testGetSearchIndexableReturnsConfigValue(): void
    {
        Config::modify()->set(ContentElement::class, 'search_indexable', true);

        $element = ContentElement::create();
        $element->Title = 'Indexable';
        $element->write();

        $this->assertTrue($element->getSearchIndexable());
    }

    public function testGetSearchIndexableDefaultsToTrue(): void
    {
        $element = ContentElement::create();
        $element->Title = 'Default';
        $element->write();

        $this->assertTrue($element->getSearchIndexable());
    }

    public function testGetSearchIndexableRespectsDisabledConfig(): void
    {
        Config::modify()->set(ContentElement::class, 'search_indexable', false);

        $element = ContentElement::create();
        $element->Title = 'Not Indexable';
        $element->write();

        $this->assertFalse($element->getSearchIndexable());
    }
}
