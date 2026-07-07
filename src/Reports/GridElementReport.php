<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Reports;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Reports\Report;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Service\LocationTrailBuilder;
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
        $elements = GridElement::get();

        $classFilter = $this->extractStringParam($params, 'ClassName');
        if ($classFilter !== null) {
            $elements = $elements->filter(['ClassName' => $classFilter]);
        }

        $pageFilter = $this->extractStringParam($params, 'PageID');

        $trailBuilder = LocationTrailBuilder::create();
        // Index every element once (unfiltered on purpose): the ancestors of a
        // class-filtered row — e.g. the Section/Row above a Column — are excluded
        // from $elements yet still needed to resolve its trail.
        $index = $trailBuilder->index(GridElement::get());

        $result = ArrayList::create();

        foreach ($elements as $element) {
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
                ? $trailBuilder->trail($element, $sitePage, $index)
                : [new TrailSegment(_t(self::class . '.ORPHANED', 'Orphaned'), null)];

            $element->LocationTrail = $this->renderTrail($segments);

            $result->push($element);
        }

        return $result;
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
     * Render location segments as a breadcrumb: each linked when it carries a CMS
     * edit URL, plain text otherwise. Labels and links are escaped at this HTML
     * boundary; the segment structure itself is produced (and tested) upstream by
     * {@see LocationTrailBuilder}.
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
