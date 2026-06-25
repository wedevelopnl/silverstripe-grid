<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use RuntimeException;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Model\GridElement;

/**
 * Migrates legacy Elemental content to the grid hierarchy on non-localised
 * (or single-locale) sites. Refuses to run when localised legacy tables are
 * present — those must use {@see MigrateGridWithFluentTask}.
 */
class MigrateGridTask extends AbstractMigrationTask
{
    protected static string $commandName = 'migrate-grid';

    protected string $title = 'Migrate grid';

    protected static string $description = 'Migrates old elemental-grid data into the Section/Row/Column hierarchy. Use --strategy=sections (default) or --strategy=single-section.';

    protected function preflight(): ?string
    {
        // Refuse on any Fluent-isolated grid, not just when localised legacy tables
        // are present: the target's isolation is the real capability boundary. This
        // task writes outside any FluentState, so on a locale-isolated grid every
        // record lands at LocaleID = 0 (invisible in every locale) — including the
        // single-locale (None) legacy model, which table-shape detection cannot see.
        $targetIsLocaleIsolated = class_exists(FluentState::class)
            && GridElement::has_extension(FluentIsolatedExtension::class);

        // detect() throws on an ambiguous mixed Fluent config. Surface that as a
        // clean preflight error (returned, not thrown) so the operator gets a
        // Command::FAILURE message rather than a raw stack trace out of run().
        try {
            $hasLocalisedContent = (new LegacyLocalisationDetector())->hasLocalisedContent();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        if ($targetIsLocaleIsolated || $hasLocalisedContent) {
            return 'Fluent site detected (locale-isolated grid or localised legacy tables). '
                . 'Run "migrate-grid-with-fluent" instead — this task migrates content without '
                . 'locale context and would write records invisible in every locale.';
        }

        return null;
    }

    protected function performMigration(
        LegacyDataReader $reader,
        FieldMapper $mapper,
        RowMappingStrategy $strategy,
        LoggerInterface $logger,
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun,
        ?array $pageIds,
    ): int {
        $service = new GridMigrationService($reader, $mapper, $strategy, $logger);

        return $service->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds);
    }
}
