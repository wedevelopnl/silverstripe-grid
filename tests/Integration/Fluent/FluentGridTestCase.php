<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;

/**
 * Shared lifecycle for the non-migration Fluent grid tests: the two-locale
 * fixture, the isolated extension on GridElement, the DRAFT + en_US bootstrap
 * with auto-scaffolding suppressed, and the manual page factory.
 *
 * Suites that exercise the scaffolding cascade itself re-enable it after
 * parent::setUp() via enableAutoScaffolding().
 *
 * Not named `*Test.php`, so PHPUnit's default suffix-based discovery skips it
 * (it is abstract and would be skipped regardless).
 */
abstract class FluentGridTestCase extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        Locale::clearCached();
        $this->disableAutoScaffolding();
        FluentState::singleton()->setLocale('en_US');
    }

    /**
     * Pages are created manually (not via fixture) because FluentExtension
     * needs an active FluentState during write to create localised records.
     */
    protected function createPage(string $title = 'Test Page'): Page
    {
        $page = Page::create();
        $page->Title = $title;
        $page->URLSegment = 'fluent-test-page';
        $page->writeToStage(Versioned::DRAFT);

        return $page;
    }
}
