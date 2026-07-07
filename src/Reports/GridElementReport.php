<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Reports;

use Override;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Reports\Report;
use WeDevelop\Grid\Model\GridElement;

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

        // Ancestor lookup keyed by ID, built once so Location trails resolve
        // in-memory instead of walking Parent() per hop per row (the report can
        // list every element on the site). Unfiltered on purpose: a class-filtered
        // row's ancestors (e.g. the Section/Row above a Column) are themselves
        // excluded from $elements and would otherwise be unreachable.
        /** @var array<int, GridElement> $elementsById */
        $elementsById = [];
        foreach (GridElement::get() as $candidate) {
            $elementsById[(int) $candidate->ID] = $candidate;
        }

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

            $element->LocationTrail = $this->buildLocationTrail($element, $sitePage, $elementsById);

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
     * Render the breadcrumb HTML for an element's location: the owning page
     * followed by each ancestor container (top-down), every segment linking into
     * the CMS. The element's own level is omitted — it is the Title column.
     *
     * @param array<int, GridElement> $elementsById
     */
    private function buildLocationTrail(GridElement $element, ?SiteTree $page, array $elementsById): string
    {
        if ($page === null) {
            return sprintf(
                '<span class="grid-report__trail"><em>%s</em></span>',
                htmlspecialchars(_t(self::class . '.ORPHANED', 'Orphaned'), ENT_QUOTES),
            );
        }

        $pageLink = Controller::join_links(
            CMSPageEditController::singleton()->Link('show'),
            (string) $page->ID,
        );

        $segments = [$this->crumb((string) $page->Title, $pageLink)];

        foreach ($this->ancestorChain($element, $elementsById) as $ancestor) {
            $segments[] = $this->crumb($this->elementLabel($ancestor), $ancestor->getCMSEditLink());
        }

        return sprintf(
            '<span class="grid-report__trail">%s</span>',
            implode(self::TRAIL_SEPARATOR, $segments),
        );
    }

    /**
     * Walk the polymorphic parent chain from the element's immediate parent up
     * to (but not including) the owning page, returned top-down (outermost first).
     *
     * Guards on ParentClass as well as ParentID: page IDs and element IDs share a
     * numeric space, so keying the lookup on ID alone would let a page-parented
     * element (a Section) resolve a same-numbered element as a false ancestor.
     *
     * @param array<int, GridElement> $elementsById
     * @return list<GridElement>
     */
    private function ancestorChain(GridElement $element, array $elementsById): array
    {
        $ancestors = [];
        $parentId = (int) $element->ParentID;
        $parentClass = (string) $element->ParentClass;

        while ($parentId > 0 && is_a($parentClass, GridElement::class, true) && isset($elementsById[$parentId])) {
            $ancestor = $elementsById[$parentId];
            $ancestors[] = $ancestor;
            $parentId = (int) $ancestor->ParentID;
            $parentClass = (string) $ancestor->ParentClass;
        }

        return array_reverse($ancestors);
    }

    /** Display label for a trail crumb: the stored title, or the type as fallback. */
    private function elementLabel(GridElement $element): string
    {
        $title = (string) $element->Title;

        return $title !== '' ? $title : $element->getType();
    }

    /** Render one breadcrumb segment, linked when a CMS edit URL is available. */
    private function crumb(string $label, ?string $link): string
    {
        $escaped = htmlspecialchars($label, ENT_QUOTES);

        if ($link === null || $link === '') {
            return sprintf('<span class="grid-report__crumb">%s</span>', $escaped);
        }

        return sprintf(
            '<a class="grid-report__crumb" href="%s">%s</a>',
            htmlspecialchars($link, ENT_QUOTES),
            $escaped,
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
