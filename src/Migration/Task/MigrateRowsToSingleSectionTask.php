<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;

class MigrateRowsToSingleSectionTask extends BuildTask
{
    private static string $segment = 'migrate-grid-rows-to-single-section';

    protected string $title = 'Migrate grid rows to single section';

    protected static string $description = 'Migrates old elemental-grid data: all ElementRows become Rows under a single Section per page.';

    public function getOptions(): array
    {
        return [
            new InputOption('default-viewport', null, InputOption::VALUE_REQUIRED, 'Old module default viewport (e.g. MD)'),
            new InputOption('zone', null, InputOption::VALUE_REQUIRED, 'Target zone for new Sections (e.g. main)'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Log what would be migrated without writing'),
            new InputOption('viewport-map', null, InputOption::VALUE_REQUIRED, 'Comma-separated old=new viewport key pairs'),
            new InputOption('page-ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated page IDs to migrate'),
        ];
    }

    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $defaultViewport = $input->getOption('default-viewport');
        $zone = $input->getOption('zone');

        if (!$defaultViewport || !$zone) {
            $output->writeln('Required options: --default-viewport, --zone');
            $output->writeln('Usage: sake dev/tasks/migrate-grid-rows-to-single-section --default-viewport=MD --zone=main');
            return Command::FAILURE;
        }

        /** @var string $defaultViewport */
        /** @var string $zone */

        $dryRun = (bool) $input->getOption('dry-run');
        $pageIdsArg = $input->getOption('page-ids');
        $pageIds = \is_string($pageIdsArg) && $pageIdsArg !== ''
            ? \array_map('intval', \explode(',', $pageIdsArg))
            : null;

        $viewportKeyMap = $this->resolveViewportKeyMap(
            \is_string($input->getOption('viewport-map')) ? $input->getOption('viewport-map') : null,
        );

        $logger = Injector::inst()->get(LoggerInterface::class);
        $reader = new LegacyDataReader();
        $mapper = new FieldMapper();
        $grouper = new ElementGrouper();
        $strategy = new AllRowsInSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap, $logger);

        $service = new GridMigrationService($reader, $mapper, $strategy, $logger);
        $service->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function resolveViewportKeyMap(?string $viewportMapArg): array
    {
        if ($viewportMapArg !== null && $viewportMapArg !== '') {
            $map = [];
            foreach (\explode(',', $viewportMapArg) as $pair) {
                $parts = \explode('=', $pair, 2);
                if (\count($parts) === 2) {
                    $map[\trim($parts[0])] = \trim($parts[1]);
                }
            }
            return $map;
        }

        /** @var GridAdapterInterface $adapter */
        $adapter = Injector::inst()->get(GridAdapterInterface::class);
        $adapterViewports = $adapter->getViewportDefinitions();
        $oldKeys = ['XS', 'SM', 'MD', 'LG', 'XL'];
        $map = [];
        foreach ($oldKeys as $oldKey) {
            foreach ($adapterViewports as $newKey => $label) {
                if (\strtolower($oldKey) === \strtolower($newKey)) {
                    $map[$oldKey] = $newKey;
                    break;
                }
            }
        }
        return $map;
    }
}
