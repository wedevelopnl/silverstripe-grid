<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Tests\Integration\Support\RecordingLogger;

/**
 * Inverse guard for WS5 #8: when a Fluent default locale DOES resolve, the
 * grid-disabled reconciliation pass must run exactly once (on the default-locale
 * pass) — not once per locale. The orchestrator gates the reconciliation on the
 * single default pass precisely because the UseGrid writes hit locale-invariant
 * base tables; repeating them per locale would be redundant I/O and log noise.
 *
 * This pins the default-resolvable path so the null-default preflight guard added
 * for the zero-locale case cannot quietly regress the normal per-locale gating.
 */
#[CoversClass(FluentMigrationOrchestrator::class)]
final class FluentDisabledPageReconciliationTest extends FluentMigrationTestCase
{
    public function testReconcilesDisabledPageExactlyOnceWhenDefaultLocaleResolves(): void
    {
        $pageId = $this->pageId();

        // Isolated model so localePlan() spans BOTH locales (en default, nl);
        // that is what makes "exactly once" distinguishable from "per locale".
        $this->seeder->addLocaleIdColumn();

        // Grid-disabled legacy page with no content: it is excluded from the
        // per-page migration loop and only touched by the reconciliation pass.
        $this->seeder->seedPage($pageId, 100, useGrid: false);

        // Pre-set UseGrid = 1 so the post-migration `false` proves the
        // reconciliation pass actively carried the legacy disable forward,
        // rather than passing on the DB default.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->UseGrid = true;
        $page->write();

        $logger = new RecordingLogger();

        $mapper = new FieldMapper();
        $strategy = new RowPerSectionStrategy(new ElementGrouper(), $mapper, self::DEFAULT_VIEWPORT, self::VIEWPORT_KEY_MAP);
        $orchestrator = new FluentMigrationOrchestrator(
            new LegacyDataReader(),
            new LegacyLocalisationDetector(),
            $mapper,
            $strategy,
            $logger,
        );

        $failures = $orchestrator->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, false, [$pageId]);

        self::assertSame(0, $failures);
        self::assertFalse($this->useGridInLocale($pageId, 'en_US'), 'disabled page UseGrid carried forward to 0');
        self::assertFalse(
            $this->useGridInLocale($pageId, 'nl_NL'),
            'UseGrid is a locale-invariant page flag, so the 0 is visible in nl_NL too',
        );

        $reconcileLogCount = \count(\array_filter(
            $logger->messages,
            static fn (array $entry): bool => \str_contains($entry['message'], 'Set UseGrid = 0'),
        ));
        self::assertSame(
            1,
            $reconcileLogCount,
            'reconciliation runs exactly once (default-locale pass), not once per locale',
        );
    }
}
