<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Reports;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Reports\Report;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Service\GridTreeService;
use WeDevelop\Grid\Value\TrailSegment;

/**
 * Lists all grid elements with their location in the CMS, supporting filtering
 * by page and element type. Flags orphaned elements (no page association).
 *
 * Each row's Title links to the element itself and the Location column renders
 * the full ancestor breadcrumb (page → section → row → …), every segment a link
 * into the CMS.
 */
class GridElementReport extends Report
{
    private const string ORPHANED_FILTER = 'orphaned';

    /** Breadcrumb separator between Location trail segments. */
    private const string TRAIL_SEPARATOR = '<span class="grid-report__sep" aria-hidden="true"> › </span>';

    #[Override]
    public function title(): string
    {
        return _t(self::class . '.TITLE', 'Grid Elements');
    }

    #[Override]
    public function description(): string
    {
        return _t(
            self::class . '.DESCRIPTION',
            'Lists all grid elements with their owning page. Use the filters to narrow by page or element type, or to find orphaned elements.',
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return SS_List<GridElement>
     */
    public function sourceRecords(array $params = []): SS_List
    {
        $classFilter = $this->extractStringParam($params, 'ClassName');
        $pageFilter = $this->extractStringParam($params, 'PageID');

        $treeService = Injector::inst()->get(GridTreeService::class);
        // Index every element once (unfiltered on purpose): the ancestors of a
        // class-filtered row — e.g. the Section/Row above a Column — are excluded
        // by the class filter yet still needed to resolve its trail. The index
        // doubles as the row source (the class filter applies in-memory below)
        // so the element table is loaded exactly once.
        $index = $treeService->indexByKey(GridElement::get());
        $this->seedParents($index);

        $result = ArrayList::create();

        foreach ($index as $element) {
            if ($classFilter !== null && $element->ClassName !== $classFilter) {
                continue;
            }

            $page = $element->getPage();
            $sitePage = $page instanceof SiteTree ? $page : null;

            if ($pageFilter === self::ORPHANED_FILTER) {
                if ($sitePage !== null) {
                    continue;
                }
            } elseif ($pageFilter !== null) {
                if ($sitePage === null) {
                    continue;
                }
                if ((string) $sitePage->ID !== $pageFilter) {
                    continue;
                }
            }

            $segments = $sitePage instanceof SiteTree
                ? $this->trailSegments($sitePage, $treeService->ancestors($element, $index))
                : [new TrailSegment(_t(self::class . '.ORPHANED', 'Orphaned'), null)];

            $element->LocationTrail = $this->renderTrail($segments);

            $result->push($element);
        }

        return $result;
    }

    /**
     * Seed every element's polymorphic Parent component in-memory so the
     * getPage()/getCMSEditLink() walks (one per row, another per Title link,
     * one per trail segment) resolve without a database fetch each — mirrors
     * the seeding GridTreeService::assembleSubTree() does during tree
     * assembly. Owning pages are batch-loaded in a single query.
     *
     * @param array<string, GridElement> $index keyed by "Class:ID" (see {@see GridTreeService::indexByKey()})
     */
    private function seedParents(array $index): void
    {
        /** @var list<int> $pageIds */
        $pageIds = [];
        foreach ($index as $element) {
            if (isset($index[$element->ParentClass . ':' . $element->ParentID])) {
                continue;
            }

            $parentClass = (string) $element->ParentClass;
            if ((int) $element->ParentID > 0 && is_a($parentClass, SiteTree::class, true)) {
                $pageIds[] = (int) $element->ParentID;
            }
        }

        /** @var array<int, SiteTree> $pagesById */
        $pagesById = [];
        if ($pageIds !== []) {
            foreach (SiteTree::get()->byIDs($pageIds) as $sitePage) {
                $pagesById[(int) $sitePage->ID] = $sitePage;
            }
        }

        foreach ($index as $element) {
            $parentKey = $element->ParentClass . ':' . $element->ParentID;
            if (isset($index[$parentKey])) {
                $element->setComponent('Parent', $index[$parentKey]);
                continue;
            }

            $page = $pagesById[(int) $element->ParentID] ?? null;
            // Match the polymorphic component fetch this replaces: a
            // class-scoped lookup only returns records of ParentClass or a
            // subclass. Anything else (orphan, non-SiteTree owner, class
            // mismatch) is left unseeded — getPage() falls back to the
            // per-element fetch for those rare rows, preserving behavior.
            if ($page !== null && is_a($page, (string) $element->ParentClass)) {
                $element->setComponent('Parent', $page);
            }
        }
    }

    /**
     * @return array<string, array<string, string|callable>>
     */
    #[Override]
    public function columns(): array
    {
        return [
            'Title' => [
                'title' => _t(self::class . '.COLUMN_TITLE', 'Title'),
                'formatting' => static function (mixed $value, GridElement $item): string {
                    $title = (string) $item->Title;
                    $label = htmlspecialchars($title !== '' ? $title : $item->getType(), ENT_QUOTES);
                    $link = $item->getCMSEditLink();

                    if ($link === null || $link === '') {
                        return $label;
                    }

                    return sprintf('<a href="%s">%s</a>', htmlspecialchars($link, ENT_QUOTES), $label);
                },
            ],
            'Type' => [
                'title' => _t(self::class . '.COLUMN_TYPE', 'Type'),
                'casting' => 'Text',
                'formatting' => static fn (mixed $value, GridElement $item): string => $item->getType(),
            ],
            'Location' => [
                'title' => _t(self::class . '.COLUMN_LOCATION', 'Location'),
                'formatting' => static function (mixed $value, GridElement $item): string {
                    /** @var string $trail */
                    $trail = $item->LocationTrail ?? '';

                    return $trail;
                },
            ],
        ];
    }

    public function parameterFields(): FieldList
    {
        $pageOptions = SiteTree::get()
            ->map('ID', 'TreeTitle')
            ->toArray();
        /** @var array<string, string> $pageOptions */
        $pageOptions[self::ORPHANED_FILTER] = _t(self::class . '.FILTER_ORPHANED', 'Orphaned elements only');

        $elementTypes = [];
        /** @var array<class-string<GridElement>, class-string<GridElement>> $subclasses */
        $subclasses = ClassInfo::subclassesFor(GridElement::class, includeBaseClass: false);
        foreach ($subclasses as $class) {
            $elementTypes[$class] = singleton($class)->getType();
        }

        return FieldList::create(
            DropdownField::create(
                'PageID',
                _t(self::class . '.FILTER_PAGE', 'Page'),
                $pageOptions,
            )->setEmptyString(_t(self::class . '.FILTER_ALL_PAGES', 'All pages')),
            DropdownField::create(
                'ClassName',
                _t(self::class . '.FILTER_TYPE', 'Element type'),
                $elementTypes,
            )->setEmptyString(_t(self::class . '.FILTER_ALL_TYPES', 'All types')),
        );
    }

    /**
     * The full location trail as structured segments: the owning page followed by
     * each ancestor container, top-down. Every segment links to its subject's CMS
     * edit screen; the element's own level is omitted.
     *
     * @param list<GridElement> $ancestors outermost first (see {@see GridTreeService::ancestors()})
     * @return list<TrailSegment>
     */
    private function trailSegments(SiteTree $page, array $ancestors): array
    {
        $segments = [new TrailSegment((string) $page->Title, $page->getCMSEditLink())];

        foreach ($ancestors as $ancestor) {
            $segments[] = new TrailSegment($this->trailLabel($ancestor), $ancestor->getCMSEditLink());
        }

        return $segments;
    }

    /** Display label for a segment: the stored title, or the element type as fallback. */
    private function trailLabel(GridElement $element): string
    {
        $title = (string) $element->Title;

        return $title !== '' ? $title : $element->getType();
    }

    /**
     * Render location segments as a breadcrumb: each linked when it carries a CMS
     * edit URL, plain text otherwise. Labels and links are escaped at this HTML
     * boundary; the segment structure itself is produced by {@see trailSegments()}
     * above.
     *
     * @param list<TrailSegment> $segments
     */
    private function renderTrail(array $segments): string
    {
        $crumbs = array_map(
            static function (TrailSegment $segment): string {
                $label = htmlspecialchars($segment->label, ENT_QUOTES);

                if ($segment->editLink === null || $segment->editLink === '') {
                    return sprintf('<span class="grid-report__crumb">%s</span>', $label);
                }

                return sprintf(
                    '<a class="grid-report__crumb" href="%s">%s</a>',
                    htmlspecialchars($segment->editLink, ENT_QUOTES),
                    $label,
                );
            },
            $segments,
        );

        return sprintf(
            '<span class="grid-report__trail">%s</span>',
            implode(self::TRAIL_SEPARATOR, $crumbs),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function extractStringParam(array $params, string $key): ?string
    {
        if (!isset($params[$key])) {
            return null;
        }

        $raw = $params[$key];
        $value = is_string($raw) || is_int($raw) ? (string) $raw : '';

        return $value !== '' ? $value : null;
    }
}
