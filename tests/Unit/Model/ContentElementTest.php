<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Core\Config\ConfigLoader;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;

/**
 * Unit tests for ContentElement::getRenderTemplates() — pure string
 * manipulation converting class names to template paths.
 */
#[CoversClass(ContentElement::class)]
final class ContentElementTest extends TestCase
{
    protected function setUp(): void
    {
        ConfigLoader::inst()->pushManifest(new MemoryConfigCollection());
    }

    protected function tearDown(): void
    {
        ConfigLoader::inst()->popManifest();
    }

    public function testGetRenderTemplatesContainsOwnClass(): void
    {
        $element = new ContentElementStub();

        $templates = $element->getRenderTemplates();

        $expected = str_replace('\\', '/', ContentElementStub::class);
        $this->assertContains($expected, $templates);
    }

    public function testGetRenderTemplatesContainsParentClass(): void
    {
        $element = new ContentElementStub();

        $templates = $element->getRenderTemplates();

        $expected = str_replace('\\', '/', ContentElement::class);
        $this->assertContains($expected, $templates);
    }

    public function testGetRenderTemplatesStopsBeforeGridElement(): void
    {
        $element = new ContentElementStub();

        $templates = $element->getRenderTemplates();

        $gridElementPath = str_replace('\\', '/', GridElement::class);
        $this->assertNotContains($gridElementPath, $templates);
    }

    public function testGetRenderTemplatesWithSuffix(): void
    {
        $element = new ContentElementStub();

        $templates = $element->getRenderTemplates('_Holder');

        $expected = str_replace('\\', '/', ContentElementStub::class) . '_Holder';
        $this->assertContains($expected, $templates);
    }

    public function testGetRenderTemplatesOrdersMostSpecificFirst(): void
    {
        $element = new ContentElementStub();

        $templates = $element->getRenderTemplates();

        $stubIndex = array_search(str_replace('\\', '/', ContentElementStub::class), $templates);
        $baseIndex = array_search(str_replace('\\', '/', ContentElement::class), $templates);

        $this->assertNotFalse($stubIndex);
        $this->assertNotFalse($baseIndex);
        $this->assertLessThan($baseIndex, $stubIndex);
    }
}

/**
 * Minimal ContentElement subclass for testing template resolution.
 */
class ContentElementStub extends ContentElement
{
    /**
     * @param array<string, mixed>|int|null $record
     */
    public function __construct(mixed $record = null, $creationType = self::CREATE_OBJECT, $queryParams = [])
    {
        // Bypass DataObject constructor
    }
}
