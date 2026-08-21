<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Reports;

use Override;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Reports\Report;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Service\GridTreeService;
use WeDevelop\Grid\Service\SharedBlockUsageResolver;

/**
 * Where every shared block is used and whether it is in sync with live.
 *
 * The usage count is distinct consuming PAGES, matching the chip in the editor
 * and the "Usage" tab in the library, so all three agree.
 */
class SharedBlockReport extends Report
{
    #[Override]
    public function title(): string
    {
        return _t(self::class . '.TITLE', 'Shared blocks');
    }

    #[Override]
    public function description(): string
    {
        return _t(
            self::class . '.DESCRIPTION',
            'Lists every shared block with its root type, how many pages use it, and whether it has unpublished changes.',
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return SS_List<SharedBlock>
     */
    public function sourceRecords(array $params = []): SS_List
    {
        $usageResolver = Injector::inst()->get(SharedBlockUsageResolver::class);
        $treeService = Injector::inst()->get(GridTreeService::class);

        /** @var ArrayList<SharedBlock> $rows */
        $rows = ArrayList::create();

        foreach (SharedBlock::get() as $block) {
            if (!$block->canView()) {
                continue;
            }

            $block->RootTypeLabel = $block->getRootTypeLabel();
            $block->UsageCount = $usageResolver->usageCount($block);
            $block->StatusLabel = $treeService->blockStatus($block)->label();

            $rows->push($block);
        }

        return $rows;
    }

    /**
     * No `formatting` callbacks: every column here names a field the report
     * already resolves, and `casting => 'Text'` runs the value through
     * `DBField::create_field('Text', ...)->XML()` before it is rendered. A
     * callback would only re-read the same property and re-escape it by hand —
     * `GridElementReport` needs them because its columns build links and
     * breadcrumbs, and this one builds nothing.
     *
     * @return array<string, array<string, mixed>|string>
     */
    #[Override]
    public function columns(): array
    {
        return [
            'Title' => [
                'title' => _t(self::class . '.COLUMN_TITLE', 'Title'),
                'casting' => 'Text',
            ],
            'RootTypeLabel' => [
                'title' => _t(self::class . '.COLUMN_ROOT_TYPE', 'Root type'),
                'casting' => 'Text',
            ],
            'UsageCount' => [
                'title' => _t(self::class . '.COLUMN_USAGE', 'Used on (pages)'),
                'casting' => 'Text',
            ],
            'StatusLabel' => [
                'title' => _t(self::class . '.COLUMN_STATUS', 'Status'),
                'casting' => 'Text',
            ],
        ];
    }
}
