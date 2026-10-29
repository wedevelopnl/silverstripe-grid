<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use Psr\Log\LoggerInterface;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\DTO\MigrationSummary;
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
        private DroppedMediaCollector $droppedMedia,
    ) {}

    /**
     * Precondition: a Fluent default locale must resolve. {@see localePlan()}
     * derives its passes from {@see Locale::getDefault()} plus the cached
     * locales, so when no default resolves (Fluent returns null only when zero
     * locales exist) the plan is EMPTY: the loop runs zero passes, no content
     * migrates, and the grid-disabled reconciliation never fires. The Fluent
     * task enforces this upstream — {@see \WeDevelop\Grid\Migration\Task\MigrateGridWithFluentTask::preflight()}
     * fails with a clear "no default locale" error rather than letting this
     * method silently no-op. When a default IS resolvable, reconciliation runs
     * on exactly the default-locale pass.
     *
     * @param array<string, string> $viewportKeyMap
     * @param list<int>|null $pageIds
     * @param bool $stopOnFirstFailure When true, halt the batch after the first page that
     *     fails to migrate. Forwarded to each per-locale {@see GridMigrationService::run()}.
     * @return array<non-empty-string, MigrationSummary> One summary per locale pass, keyed by
     *     locale code in pass order. Never summed: a page converted in two locales is not two pages.
     */
    public function run(
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun = false,
        ?array $pageIds = null,
        bool $stopOnFirstFailure = false,
    ): array {
        $model = $this->detector->detect();

        $default = Locale::getDefault();
        $defaultCode = $default?->Locale;

        $plan = $this->localePlan($model);
        $chains = [];
        foreach ($plan as $localeCode => $locale) {
            $chains[$localeCode] = $this->chainOf($locale, $localeCode);
        }

        $summaries = [];
        foreach ($plan as $localeCode => $locale) {
            $localeId = $locale->ID;
            $chain = $chains[$localeCode];
            $isDefault = $localeCode === $defaultCode;
            $otherLocaleChains = $isDefault ? \array_values(\array_diff_key($chains, [$localeCode => true])) : [];
            $summaries[$localeCode] = FluentState::singleton()->withState(
                function (FluentState $state) use (
                    $localeCode,
                    $localeId,
                    $chain,
                    $model,
                    $defaultViewport,
                    $zone,
                    $viewportKeyMap,
                    $dryRun,
                    $pageIds,
                    $isDefault,
                    $otherLocaleChains,
                    $stopOnFirstFailure,
                ): MigrationSummary {
                    $state->setLocale($localeCode);

                    $this->logger->info('Migrating locale {locale}{default}.', [
                        'locale' => $localeCode,
                        'default' => $isDefault ? ' (default)' : '',
                    ]);

                    $source = new LocaleScopedLegacySource(
                        $this->reader,
                        $model,
                        $localeCode,
                        $localeId,
                        $chain,
                        $isDefault,
                        $otherLocaleChains,
                    );
                    $service = new GridMigrationService(
                        $source,
                        $this->mapper,
                        $this->strategy,
                        $this->logger,
                        $this->droppedMedia,
                        $localeCode,
                    );

                    // Reconcile grid-disabled pages once: the UseGrid writes hit
                    // locale-invariant base tables, so only the default-locale pass
                    // needs them — repeating per locale is redundant I/O and log noise.
                    return $service->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds, $isDefault, $stopOnFirstFailure);
                },
            );
        }

        return $summaries;
    }

    /**
     * Locales to migrate, default first. Only the default locale is migrated
     * when neither the elements nor any page's area are localised (legacy
     * content is single-locale and must not be duplicated into other locales).
     *
     * @return array<non-empty-string, Locale> locale code → Locale record (ID is positive-int)
     */
    private function localePlan(LegacyLocalisationModel $model): array
    {
        $default = Locale::getDefault();

        /** @var array<non-empty-string, Locale> $plan */
        $plan = [];
        if ($default !== null) {
            $plan[$default->Locale] = $default;
        }

        if ($model === LegacyLocalisationModel::None && $this->detector->perLocaleAreaTables() === []) {
            return $plan;
        }

        foreach (Locale::getCached() as $locale) {
            $plan[$locale->Locale] = $locale;
        }

        return $plan;
    }

    /**
     * @param non-empty-string $localeCode
     * @return non-empty-list<non-empty-string> the locale, then its fallbacks in Sort order
     */
    private function chainOf(Locale $locale, string $localeCode): array
    {
        $chain = [$localeCode];
        foreach ($locale->getChain() as $link) {
            if (!\in_array($link->Locale, $chain, true)) {
                $chain[] = $link->Locale;
            }
        }

        return $chain;
    }
}
