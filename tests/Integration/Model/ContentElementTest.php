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

    public function testGetSummaryReturnsEmptyStringWhenHtmlIsEmpty(): void
    {
        $element = ContentElement::create();

        self::assertSame('', $element->getSummary());
    }

    public function testGetSummaryStripsHtmlAndReturnsPlainText(): void
    {
        $element = ContentElement::create();
        $element->HTML = '<p>Hello <strong>editor</strong>, welcome.</p>';

        $summary = $element->getSummary();

        self::assertNotNull($summary);
        self::assertStringNotContainsString('<', $summary);
        self::assertStringNotContainsString('>', $summary);
        self::assertStringContainsString('Hello', $summary);
        self::assertStringContainsString('editor', $summary);
    }

    public function testGetSummaryTruncatesAtConfiguredWordCount(): void
    {
        Config::modify()->set(ContentElement::class, 'summary_word_count', 3);

        $element = ContentElement::create();
        $element->HTML = '<p>one two three four five six seven eight</p>';

        $summary = $element->getSummary();

        self::assertNotNull($summary);
        self::assertStringContainsString('one', $summary);
        self::assertStringContainsString('three', $summary);
        self::assertStringNotContainsString('eight', $summary);
    }
}
