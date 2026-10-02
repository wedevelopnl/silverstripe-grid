<?php

declare(strict_types=1);

namespace WeDevelop\Grid\AdminToolbar;

use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Security\Member;
use WeDevelop\AdminToolbar\Model\Menu;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlockReference;

// The toolbar is optional. The config manifest class_exists()-checks every
// class it indexes, which autoloads this file: without the guard the `extends`
// below is a fatal error on flush wherever the toolbar is not installed.
if (!class_exists(Menu::class)) {
    return;
}

/**
 * The current page's grid as a read-only map with edit links, offered to
 * wedevelopnl/silverstripe-admintoolbar, which discovers every concrete Menu.
 */
class GridMenu extends Menu
{
    private static int $order = 30;

    private static string $title = 'Grid';

    private static string $icon = 'font-icon-block-layout';

    /** @var list<string> */
    private static array $stylesheets = [
        'wedevelopnl/silverstripe-grid:client/dist/styles/admin-toolbar-menu.css',
    ];

    /** @var array<string, string> */
    private static array $dependencies = [
        'gridAdapter' => '%$' . GridAdapterInterface::class,
    ];

    public GridAdapterInterface $gridAdapter;

    /** @var ArrayList<ArrayData>|null */
    private ?ArrayList $zones = null;

    /** Shown only when the dialog would list a node the member can view. */
    public function isSupported(): bool
    {
        return $this->getZones()->exists();
    }

    /**
     * Zones in display order: `main` first, the rest alphabetically. A zone
     * without a root the member can view is left out, as are zone-less roots
     * (they predate zones; see BackfillGridZoneTask). Built once per menu,
     * which lives for one toolbar render.
     *
     * @return ArrayList<ArrayData>
     */
    public function getZones(): ArrayList
    {
        return $this->zones ??= $this->buildZones();
    }

    /**
     * @return ArrayList<ArrayData>
     */
    private function buildZones(): ArrayList
    {
        $page = $this->getContext()->page;
        $member = $this->getContext()->member;

        if ($page === null || !$page->hasExtension(GridPageExtension::class) || !$page->usesGrid()) {
            return ArrayList::create();
        }

        // Not columnUnique(): the default Sort order joins the DISTINCT and repeats zones.
        /** @var list<string> $zones */
        $zones = $page->GridRoots()->column('Zone');
        $names = array_unique(array_filter($zones));
        usort($names, static fn (string $a, string $b): int => [$a !== 'main', $a] <=> [$b !== 'main', $b]);

        $visible = [];

        foreach ($names as $name) {
            /** @var ArrayList<GridElement> $roots */
            $roots = $page->GridZone($name);
            $nodes = $this->nodes($roots, $member);

            if ($nodes->exists()) {
                $visible[] = ['Name' => $name, 'Nodes' => $nodes];
            }
        }

        return ArrayList::create(array_map(
            static fn (array $zone): ArrayData => ArrayData::create($zone + ['ShowHeading' => count($visible) > 1]),
            $visible,
        ));
    }

    /**
     * @param SS_List<GridElement> $elements
     * @return ArrayList<ArrayData>
     */
    private function nodes(SS_List $elements, Member $member): ArrayList
    {
        $nodes = ArrayList::create();

        foreach ($elements as $element) {
            if ($element->canView($member)) {
                $nodes->push($this->node($element, $member));
            }
        }

        return $nodes;
    }

    private function node(GridElement $element, Member $member): ArrayData
    {
        $kind = match (true) {
            $element instanceof SharedBlockReference => $this->sharedNode($element, $member),
            $element instanceof Section => ['Kind' => 'section'],
            $element instanceof Row => ['Kind' => 'row', 'Of' => $this->gridAdapter->getColumnCount()],
            $element instanceof Column => ['Kind' => 'column', 'Span' => $element->getGridSettings()->default->width],
            default => ['Kind' => 'element'],
        };

        return ArrayData::create($kind + [
            'Title' => $element->Title ?: $element->getType(),
            'Icon' => $element->config()->get('icon'),
            'Link' => $element->canEdit($member) ? $element->getCMSEditLink() : null,
            'Children' => $element instanceof ContainerInterface
                ? $this->nodes($this->children($element), $member)
                : ArrayList::create(),
        ]);
    }

    /**
     * Containers hold only grid elements; the relation is typed as its DataObject base.
     *
     * @param ContainerInterface<GridElement> $container
     * @return SS_List<GridElement>
     */
    private function children(ContainerInterface $container): SS_List
    {
        /** @var SS_List<GridElement> $children */
        $children = $container->getChildren();

        return $children;
    }

    /**
     * A placement is one leaf, edited in the shared-block library rather than
     * on the page. Standing in for a column, it spans what that column spans.
     * A stranded placement resolves an unsaved block, which has no root and no link.
     *
     * @return array{Kind: 'shared', Link: string|null, Span?: positive-int}
     */
    private function sharedNode(SharedBlockReference $reference, Member $member): array
    {
        $block = $reference->Block();
        $root = $block?->getRootElement();
        $node = ['Kind' => 'shared', 'Link' => $block?->canEdit($member) ? $block->getCMSEditLink() : null];

        return $root instanceof Column ? $node + ['Span' => $root->getGridSettings()->default->width] : $node;
    }
}
