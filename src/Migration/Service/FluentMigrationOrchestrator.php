<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use Psr\Log\LoggerInterface;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * Locale-aware migration orchestration for Fluent sites.
 *
 * Detects the legacy localisation model, then runs the existing per-page
 * migration pipeline once per locale inside FluentState (default locale
 * first, so base GridElement records are stamped with the default LocaleID).
 * Per-locale idempotency falls out of FluentIsolatedExtension scoping
 * GridElement queries to the active locale.
 */
final readonly class FluentMigrationOrchestrator
{
    public function __construct(
        private LegacyDataReader $reader,
        private LegacyLocalisationDetector $detector,
        private FieldMapper $mapper,
        private RowMappingStrategy $strategy,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $viewportKeyMap
     * @param list<int>|null $pageIds
     * @param bool $stopOnFirstFailure When true, halt the batch after the first page that
     *     fails to migrate. Forwarded to each per-locale {@see GridMigrationService::run()}.
     * @return int<0, max> Total pages that failed to migrate across all locales
     */
    public function run(
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun = false,
        ?array $pageIds = null,
        bool $stopOnFirstFailure = false,
    ): int {
        $model = $this->detector->detect();

        $default = Locale::getDefault();
        $defaultCode = $default?->Locale;

        $failures = 0;
        foreach ($this->localePlan($model) as $localeCode => $localeId) {
            $isDefault = $localeCode === $defaultCode;
            $failures += FluentState::singleton()->withState(
                function (FluentState $state) use (
                    $localeCode,
                    $localeId,
                    $model,
                    $defaultViewport,
                    $zone,
                    $viewportKeyMap,
                    $dryRun,
                    $pageIds,
                    $isDefault,
                    $stopOnFirstFailure,
                ): int {
                    $state->setLocale($localeCode);

                    $this->logger->info('Migrating locale {locale}{default}.', [
                        'locale' => $localeCode,
                        'default' => $isDefault ? ' (default)' : '',
                    ]);

                    $source = new LocaleScopedLegacySource($this->reader, $model, $localeCode, $localeId);
                    $service = new GridMigrationService($source, $this->mapper, $this->strategy, $this->logger);

                    // Reconcile grid-disabled pages once: the UseGrid writes hit
                    // locale-invariant base tables, so only the default-locale pass
                    // needs them — repeating per locale is redundant I/O and log noise.
                    return $service->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds, $isDefault, $stopOnFirstFailure);
                },
            );
        }

        return $failures;
    }

    /**
     * Locales to migrate, default first. For the None model only the default
     * locale is migrated (legacy content is single-locale and must not be
     * duplicated into other locales).
     *
     * @return array<non-empty-string, positive-int> locale code → Locale record ID
     */
    private function localePlan(LegacyLocalisationModel $model): array
    {
        $default = Locale::getDefault();

        /** @var array<non-empty-string, positive-int> $plan */
        $plan = [];
        if ($default !== null) {
            $plan[$default->Locale] = $default->ID;
        }

        if ($model === LegacyLocalisationModel::None) {
            return $plan;
        }

        foreach (Locale::getCached() as $locale) {
            $plan[$locale->Locale] = $locale->ID;
        }

        return $plan;
    }
}
