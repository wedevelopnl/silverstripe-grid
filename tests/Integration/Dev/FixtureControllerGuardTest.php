<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Dev\FixtureController;

/**
 * Guards the destructive reset endpoint behind an explicit opt-in query
 * parameter so an accidental curl/browser hit cannot wipe fixture data.
 */
final class FixtureControllerGuardTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testResetRequiresConfirmQueryParam(): void
    {
        $response = $this->post('/dev/grid-fixtures/reset', []);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testResetSucceedsWithConfirm(): void
    {
        $response = $this->post('/dev/grid-fixtures/reset?confirm=1', []);
        self::assertSame(200, $response->getStatusCode());
    }
}
