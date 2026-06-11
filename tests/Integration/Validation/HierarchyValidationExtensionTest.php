<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\HierarchyValidationExtension;

#[CoversClass(HierarchyValidationExtension::class)]
final class HierarchyValidationExtensionTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    public function testValidWriteSucceeds(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        // Section at page level is valid — write should not throw
        $section = GridTreeFactory::section($page);

        self::assertGreaterThan(0, $section->ID);
    }

    public function testInvalidWriteThrowsValidationException(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $this->expectException(ValidationException::class);

        // Row at page level violates can_be_root: false
        $row = Row::create();
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;
        $row->write();
    }

    /**
     * The write-time path must surface the i18n-translated message (via
     * ValidationError::translate()), not the raw English message — matching the
     * API path (GridController maps errors with translate()).
     *
     * The module ships an i18n entry for the PAGE_LEVEL_REJECTED key whose
     * wording differs from the inline English fallback ("...at the page level."
     * vs the fallback's "...at page level."). Seeing the lang-file phrasing in
     * the thrown message proves translate() resolved the catalogue rather than
     * emitting the raw ValidationError::$message, and that the {element} param
     * was injected.
     */
    public function testWriteTimeErrorUsesTranslatedMessage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $row = Row::create();
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;

        try {
            $row->write();
            self::fail('Row at page level must fail validation.');
        } catch (ValidationException $e) {
            $messages = $e->getResult()->getMessages();
            $combined = implode("\n", array_column($messages, 'message'));

            self::assertStringContainsString(
                'cannot be placed at the page level.',
                $combined,
                'write-time validation must use ValidationError::translate(), not the raw message',
            );
            self::assertStringContainsString(
                $row->singular_name(),
                $combined,
                'the {element} param must be injected into the translated message',
            );
        }
    }
}
