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
 * Lists all grid elements with their owning page, supporting filtering
 * by page and element type. Flags orphaned elements (no page association).
 */
class GridElementReport extends Report
{
    private const string ORPHANED_FILTER = 'orphaned';

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

        $result = ArrayList::create();

        foreach ($elements as $element) {
            $page = $element->getPage();

            if ($page instanceof SiteTree) {
                $element->PageTitle = $page->Title;
                $element->PageCMSLink = Controller::join_links(
                    CMSPageEditController::singleton()->Link('show'),
                    (string) $page->ID,
                );
            } else {
                $element->PageTitle = _t(self::class . '.ORPHANED', 'Orphaned');
                $element->PageCMSLink = null;
            }

            if ($pageFilter === self::ORPHANED_FILTER) {
                if ($page instanceof SiteTree) {
                    continue;
                }
            } elseif ($pageFilter !== null) {
                if (!$page instanceof SiteTree) {
                    continue;
                }
                if ((string) $page->ID !== $pageFilter) {
                    continue;
                }
            }

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
            ],
            'Type' => [
                'title' => _t(self::class . '.COLUMN_TYPE', 'Type'),
                'casting' => 'Text',
                'formatting' => static fn (mixed $value, GridElement $item): string => $item->getType(),
            ],
            'PageTitle' => [
                'title' => _t(self::class . '.COLUMN_PAGE', 'Page'),
                'formatting' => static function (mixed $value, GridElement $item): string {
                    $title = is_string($value) ? $value : '';

                    /** @var string|null $link */
                    $link = $item->PageCMSLink;

                    if ($link === null) {
                        return sprintf('<em>%s</em>', htmlspecialchars($title, ENT_QUOTES));
                    }

                    return sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($link, ENT_QUOTES),
                        htmlspecialchars($title, ENT_QUOTES),
                    );
                },
            ],
            'LastEdited' => [
                'title' => _t(self::class . '.COLUMN_LAST_EDITED', 'Last Edited'),
                'casting' => 'Datetime->Ago',
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
