<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;

#[CoversClass(ContentElement::class)]
final class ContentElementTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testGetRenderTemplatesReplacesBackslashWithSlash(): void
    {
        $element = ContentElement::create();
        $element->Title = 'Test';
        $element->write();

        $templates = $element->getRenderTemplates();

        foreach ($templates as $template) {
            $this->assertStringNotContainsString('\\', $template);
            $this->assertStringContainsString('/', $template);
        }
    }

    public function testGetRenderTemplatesAppendsSuffix(): void
    {
        $element = ContentElement::create();
        $element->Title = 'Test';
        $element->write();

        $templates = $element->getRenderTemplates('_holder');

        foreach ($templates as $template) {
            $this->assertStringEndsWith('_holder', $template);
        }
    }

    public function testGetRenderTemplatesStopsBeforeGridElement(): void
    {
        $element = ContentElement::create();
        $element->Title = 'Test';
        $element->write();

        $templates = $element->getRenderTemplates();

        // ContentElement extends GridElement directly, so only 1 template
        $this->assertCount(1, $templates);

        // Should contain ContentElement path but not GridElement
        $this->assertStringContainsString('ContentElement', $templates[0]);
    }
}
