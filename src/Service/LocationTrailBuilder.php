<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\TrailSegment;

/**
 * Resolves an element's place in the grid hierarchy for display.
 *
 * Ancestors are resolved from an in-memory index keyed by the composite
 * "ParentClass:ParentID" string — the same collision-safe keying used by
 * {@see GridTreeBuilder}. Page IDs and element IDs share a numeric space, so
 * embedding the class in the key prevents a page-parented element from
 * resolving a same-numbered element as a false ancestor.
 */
class LocationTrailBuilder
{
    use Injectable;

    /**
     * Index elements by their own "Class:ID" key for O(1) ancestor lookup.
     *
     * Pass every element the caller cares about (typically the full result set):
     * an ancestor missing from the index simply terminates the walk early.
     *
     * @param iterable<GridElement> $elements
     * @return array<string, GridElement>
     */
    public function index(iterable $elements): array
    {
        $index = [];
        foreach ($elements as $element) {
            $index[$element::class . ':' . $element->ID] = $element;
        }

        return $index;
    }

    /**
     * The element's ancestor containers, outermost first (Section → Row → Column).
     *
     * The element's own level and the owning page are excluded — the page is not
     * a GridElement and is provided separately to {@see trail()}.
     *
     * @param array<string, GridElement> $index keyed by "Class:ID" (see {@see index()})
     * @return list<GridElement>
     */
    public function ancestors(GridElement $element, array $index): array
    {
        $ancestors = [];
        $parentKey = $element->ParentClass . ':' . $element->ParentID;

        while (isset($index[$parentKey])) {
            $parent = $index[$parentKey];
            $ancestors[] = $parent;
            $parentKey = $parent->ParentClass . ':' . $parent->ParentID;
        }

        return array_reverse($ancestors);
    }

    /**
     * The full location trail as structured segments: the owning page followed by
     * each ancestor container, top-down. Every segment links to its subject's CMS
     * edit screen; the element's own level is omitted.
     *
     * @param array<string, GridElement> $index keyed by "Class:ID" (see {@see index()})
     * @return list<TrailSegment>
     */
    public function trail(GridElement $element, SiteTree $page, array $index): array
    {
        $segments = [new TrailSegment((string) $page->Title, $page->getCMSEditLink())];

        foreach ($this->ancestors($element, $index) as $ancestor) {
            $segments[] = new TrailSegment($this->label($ancestor), $ancestor->getCMSEditLink());
        }

        return $segments;
    }

    /** Display label for a segment: the stored title, or the element type as fallback. */
    private function label(GridElement $element): string
    {
        $title = (string) $element->Title;

        return $title !== '' ? $title : $element->getType();
    }
}
