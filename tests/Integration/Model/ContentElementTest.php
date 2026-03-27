<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Model\ContentElement;

#[CoversClass(ContentElement::class)]
final class ContentElementTest extends SapphireTest
{
    protected static $fixture_file = null;

    public function testGetSearchIndexableReturnsTrueByDefault(): void
    {
        $element = ContentElement::create();

        self::assertTrue($element->getSearchIndexable());
    }

    public function testGetSearchIndexableRespectsConfig(): void
    {
        Config::modify()->set(ContentElement::class, 'search_indexable', false);

        $element = ContentElement::create();

        self::assertFalse($element->getSearchIndexable());
    }
}
