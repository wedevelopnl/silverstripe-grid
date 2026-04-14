import { useCallback, useMemo, useRef, useState } from 'react';
import type { SectionNode, RowNode, ColumnNode, SimpleElementNode } from '@/types/elements';
import type {
  EnrichedSectionNode,
  EnrichedRowNode,
  EnrichedColumnNode,
  EnrichedSimpleElementNode,
} from '@/types/enriched';
import { buildDraggableId, getDraggableTypeForNode } from '@/types/dnd';

export function buildStorageKey(areaId: number): string {
  return `grid:collapsed:${String(areaId)}`;
}

function readCollapsedIds(key: string): ReadonlySet<number> {
  try {
    const raw = localStorage.getItem(key);
    if (raw === null) {
      return new Set();
    }

    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return new Set();
    }

    return new Set(parsed.filter((v): v is number => typeof v === 'number'));
  } catch {
    return new Set();
  }
}

function writeCollapsedIds(key: string, ids: ReadonlySet<number>): void {
  try {
    localStorage.setItem(key, JSON.stringify([...ids]));
  } catch {
    // QuotaExceededError or SecurityError — silently ignore
  }
}

function enrichElement(element: SimpleElementNode): EnrichedSimpleElementNode {
  return {
    ...element,
    sortableId: buildDraggableId(getDraggableTypeForNode(element), element.id),
  };
}

function enrichColumn(
  column: ColumnNode,
  collapsedIds: ReadonlySet<number>,
  getToggle: (elementId: number) => () => void,
): EnrichedColumnNode {
  const enrichedChildren = column.children?.map(enrichElement) ?? null;
  return {
    ...column,
    sortableId: buildDraggableId(getDraggableTypeForNode(column), column.id),
    childSortableIds: enrichedChildren?.map((c) => c.sortableId) ?? [],
    isCollapsed: collapsedIds.has(column.id),
    toggle: getToggle(column.id),
    children: enrichedChildren,
  };
}

function enrichRow(
  row: RowNode,
  collapsedIds: ReadonlySet<number>,
  getToggle: (elementId: number) => () => void,
): EnrichedRowNode {
  const enrichedChildren =
    row.children?.map((col) => enrichColumn(col, collapsedIds, getToggle)) ?? null;
  return {
    ...row,
    sortableId: buildDraggableId(getDraggableTypeForNode(row), row.id),
    childSortableIds: enrichedChildren?.map((c) => c.sortableId) ?? [],
    isCollapsed: collapsedIds.has(row.id),
    toggle: getToggle(row.id),
    children: enrichedChildren,
  };
}

function enrichSection(
  section: SectionNode,
  collapsedIds: ReadonlySet<number>,
  getToggle: (elementId: number) => () => void,
): EnrichedSectionNode {
  const enrichedChildren =
    section.children?.map((row) => enrichRow(row, collapsedIds, getToggle)) ?? null;
  return {
    ...section,
    sortableId: buildDraggableId(getDraggableTypeForNode(section), section.id),
    childSortableIds: enrichedChildren?.map((c) => c.sortableId) ?? [],
    isCollapsed: collapsedIds.has(section.id),
    toggle: getToggle(section.id),
    children: enrichedChildren,
  };
}

export function useTreeEnrichment(
  sections: readonly SectionNode[],
  areaId: number,
): readonly EnrichedSectionNode[] {
  const storageKey = buildStorageKey(areaId);

  const [collapsedIds, setCollapsedIds] = useState<ReadonlySet<number>>(() =>
    readCollapsedIds(storageKey),
  );

  const toggle = useCallback(
    (elementId: number) => {
      setCollapsedIds((prev) => {
        const next = new Set(prev);

        if (next.has(elementId)) {
          next.delete(elementId);
        } else {
          next.add(elementId);
        }

        writeCollapsedIds(storageKey, next);
        return next;
      });
    },
    [storageKey],
  );

  // Memoize per-id toggle callbacks so the React tree gets referentially stable
  // handlers across renders. Without this, every re-enrichment allocates new
  // closures, defeating React.memo on child rows/columns and causing cascades.
  const toggleCallbacks = useRef<Map<number, () => void>>(new Map());
  const lastToggleRef = useRef(toggle);
  if (lastToggleRef.current !== toggle) {
    // toggle identity changed (storageKey changed) — invalidate cached closures
    // so they call the new toggle bound to the new storage key.
    toggleCallbacks.current = new Map();
    lastToggleRef.current = toggle;
  }

  return useMemo(() => {
    const cache = toggleCallbacks.current;
    const visited = new Set<number>();

    const getToggle = (elementId: number): (() => void) => {
      visited.add(elementId);
      let cb = cache.get(elementId);
      if (cb === undefined) {
        cb = () => {
          toggle(elementId);
        };
        cache.set(elementId, cb);
      }
      return cb;
    };

    const enriched = sections.map((section) => enrichSection(section, collapsedIds, getToggle));

    // Prune stale entries for nodes that no longer exist in the tree
    for (const id of cache.keys()) {
      if (!visited.has(id)) {
        cache.delete(id);
      }
    }

    return enriched;
  }, [sections, collapsedIds, toggle]);
}
