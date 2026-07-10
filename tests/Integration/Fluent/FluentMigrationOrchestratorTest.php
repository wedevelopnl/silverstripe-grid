<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\RejectAllWritesExtension;

/**
 * Pins the orchestration itself — the per-locale plan, the default-locale flag and
 * the argument defaults — rather than the migrated content, which the per-model
 * Fluent tests already cover.
 */
#[CoversClass(FluentMigrationOrchestrator::class)]
final class FluentMigrationOrchestratorTest extends FluentMigrationTestCase
{
    private function makeOrchestrator(LoggerInterface $logger): FluentMigrationOrchestrator
    {
        $mapper = new FieldMapper();

        return new FluentMigrationOrchestrator(
            new LegacyDataReader(),
            new LegacyLocalisationDetector(),
            $mapper,
            new RowPerSectionStrategy(new ElementGrouper(), $mapper, self::DEFAULT_VIEWPORT, self::VIEWPORT_KEY_MAP),
            $logger,
        );
    }

    /** @return object{messages: list<array{level: string, message: string, context: array<string, mixed>}>} */
    private function recordingLogger(): object
    {
        return new class () extends NullLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    public function testLogsOnePerLocalePassAndMarksOnlyTheDefaultLocale(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(1, 100, self::CONTENT_CLASS, 1, ['Title' => 'Hello']);
        // A localised model yields a multi-locale plan; the None model would collapse to one.
        $this->seeder->addFieldLocalisedTables();

        $logger = $this->recordingLogger();
        $this->makeOrchestrator($logger)->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, false, [$pageId]);

        $passes = [];
        foreach ($logger->messages as $entry) {
            if ($entry['message'] === 'Migrating locale {locale}{default}.') {
                $passes[] = $entry['context'];
            }
        }

        // Exactly one pass per configured locale, default-first, and only the global
        // default carries the " (default)" suffix.
        self::assertSame(
            [
                ['locale' => 'en_US', 'default' => ' (default)'],
                ['locale' => 'nl_NL', 'default' => ''],
            ],
            $passes,
        );
    }

    public function testFailuresAreAccumulatedAcrossEveryPageAndEveryLocalePass(): void
    {
        $firstPageId = $this->pageId();
        $this->seeder->seedPage($firstPageId, 100);
        $this->seeder->seedElement(1, 100, self::CONTENT_CLASS, 1, ['Title' => 'Hello']);

        $second = \Page::create();
        $second->Title = 'Second';
        $second->write();
        $secondPageId = (int) $second->ID;
        $this->seeder->seedPage($secondPageId, 101);
        $this->seeder->seedElement(2, 101, self::CONTENT_CLASS, 1, ['Title' => 'World']);

        $this->seeder->addFieldLocalisedTables();

        // Every element write is rejected, so both pages fail in both locales. The
        // expected total of 4 pins three defaults at once: the batch does not stop at the
        // first failing page, and the per-locale counts are summed (not overwritten or
        // subtracted).
        GridElement::add_extension(RejectAllWritesExtension::class);

        try {
            $failures = $this->makeOrchestrator(new NullLogger())
                ->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, false, [$firstPageId, $secondPageId]);
        } finally {
            GridElement::remove_extension(RejectAllWritesExtension::class);
        }

        self::assertSame(4, $failures, '2 pages x 2 locales, accumulated');
    }

    public function testRunReturnsZeroWhenNoDefaultLocaleResolves(): void
    {
        // No locales at all: the default-locale lookup returns null and the plan is empty.
        // The reader of $default->Locale must tolerate that rather than dereference null.
        Locale::get()->removeAll();
        Locale::clearCached();

        $failures = $this->makeOrchestrator(new NullLogger())
            ->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP);

        self::assertSame(0, $failures);
    }

    public function testRunWritesByDefaultRatherThanDryRunning(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(1, 100, self::CONTENT_CLASS, 1, ['Title' => 'Hello']);

        // dryRun is not passed: the default must be "actually write".
        $failures = $this->makeOrchestrator(new NullLogger())
            ->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP);

        self::assertSame(0, $failures);
        self::assertGreaterThan(0, Section::get()->count(), 'a non-dry run must persist the migrated hierarchy');
    }
}
