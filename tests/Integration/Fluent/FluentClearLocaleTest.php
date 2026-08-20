<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Delete\DeleteLocalisationPolicy;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridAwareDeleteLocalisationPolicy;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Pins what is unique to the CMS clear-locale entry point: the stock
 * DeleteLocalisationPolicy resolves to the grid-aware subclass via Injector,
 * and clearing a locale without grid elements is a safe no-op.
 *
 * The cascade-delete behaviour itself is owned by
 * {@see GridAwareDeleteLocalisationPolicyTest}.
 */
#[CoversNothing]
final class FluentClearLocaleTest extends FluentGridTestCase
{
    /**
     * clearFluent uses DeleteLocalisationPolicy::create(); the DI binding in
     * _config/fluent.yml must swap in the grid-aware policy or grid elements
     * survive a locale clear.
     */
    public function testDeleteLocalisationPolicyResolvesToGridAwarePolicy(): void
    {
        self::assertInstanceOf(
            GridAwareDeleteLocalisationPolicy::class,
            DeleteLocalisationPolicy::create(),
        );
    }

    /**
     * Clearing a locale that has no grid elements should not cause errors
     * and should not affect other locales.
     */
    public function testClearLocaleWithNoGridElementsIsNoop(): void
    {
        $page = $this->createPage();

        // Build tree only in English
        $section = GridTreeFactory::section($page, title: 'EN Only');
        GridTreeFactory::row($section);

        // Localise page to Dutch (no grid elements created)
        FluentState::singleton()->setLocale('nl_NL');
        $page->writeToStage(Versioned::DRAFT);

        // Clear Dutch locale
        $policy = DeleteLocalisationPolicy::create();
        $policy->delete($page);

        // English should be unaffected
        FluentState::singleton()->setLocale('en_US');
        self::assertCount(1, Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]), 'English Section should be intact');
    }
}
