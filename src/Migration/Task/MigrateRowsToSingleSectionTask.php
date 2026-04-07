<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
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

    private static string $description = 'Migrates old elemental-grid data: all ElementRows become Rows under a single Section per page.';

    public function run(HTTPRequest $request): void
    {
        $defaultViewport = $request->getVar('default_viewport');
        $zone = $request->getVar('zone');
        if (!$defaultViewport || !$zone) {
            echo "Required arguments: default_viewport, zone\n";
            echo "Usage: sake dev/tasks/migrate-grid-rows-to-single-section default_viewport=MD zone=main\n";
            return;
        }

        $dryRun = (bool) $request->getVar('dry-run');
        $pageIdsArg = $request->getVar('page-ids');
        $pageIds = $pageIdsArg
            ? \array_map('intval', \explode(',', $pageIdsArg))
            : null;

        $viewportKeyMap = $this->resolveViewportKeyMap(
            $request->getVar('viewport-map'),
        );

        $logger = Injector::inst()->get(LoggerInterface::class);
        $reader = new LegacyDataReader();
        $mapper = new FieldMapper();
        $grouper = new ElementGrouper();
        $strategy = new AllRowsInSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap, $logger);

        $service = new GridMigrationService($reader, $mapper, $strategy, $logger);
        $service->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds);
    }

    /**
     * Parse viewport-map arg or derive from adapter.
     *
     * @return array<string, string> old key → new key
     */
    protected function resolveViewportKeyMap(?string $viewportMapArg): array
    {
        // Duplicate of MigrateRowsToSectionsTask — intentional for thin shells
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
