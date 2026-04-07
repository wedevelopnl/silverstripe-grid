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
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;

class MigrateRowsToSectionsTask extends BuildTask
{
    private static string $segment = 'migrate-grid-rows-to-sections';

    private static string $title = 'Migrate grid rows to sections';

    private static string $description = 'Migrates old elemental-grid data: each ElementRow becomes a Section + Row in the new hierarchy.';

    public function run(HTTPRequest $request): void
    {
        // 1. Parse and validate required args
        $defaultViewport = $request->getVar('default_viewport');
        $zone = $request->getVar('zone');
        if (!$defaultViewport || !$zone) {
            echo "Required arguments: default_viewport, zone\n";
            echo "Usage: sake dev/tasks/migrate-grid-rows-to-sections default_viewport=MD zone=main\n";
            return;
        }

        $dryRun = (bool) $request->getVar('dry-run');
        $pageIdsArg = $request->getVar('page-ids');
        $pageIds = $pageIdsArg
            ? \array_map('intval', \explode(',', $pageIdsArg))
            : null;

        // 2. Derive viewport key map
        $viewportKeyMap = $this->resolveViewportKeyMap(
            $request->getVar('viewport-map'),
        );

        // 3. Wire dependencies and run
        $logger = Injector::inst()->get(LoggerInterface::class);
        $reader = new LegacyDataReader();
        $mapper = new FieldMapper();
        $grouper = new ElementGrouper();
        $strategy = new RowPerSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap);

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

        // Auto-derive: case-insensitive match of old keys against adapter viewports
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
