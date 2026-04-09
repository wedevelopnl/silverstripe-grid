import { vi } from 'vitest';
import type {
  EnrichedColumnNode,
  EnrichedRowNode,
  EnrichedSectionNode,
  EnrichedSimpleElementNode,
} from '@/types/enriched';
import type { ColumnNode, RowNode, SectionNode, SimpleElementNode } from '@/types/elements';
import { buildDraggableId } from '@/types/dnd';
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
} from './factories';

export function createEnrichedElement(
  overrides?: Partial<SimpleElementNode & { isCollapsed?: boolean }>,
): EnrichedSimpleElementNode {
  const base = createSimpleElement(overrides);
  return {
    ...base,
    sortableId: buildDraggableId('element', base.id),
  };
}

export function createEnrichedColumn(
  overrides?: Partial<ColumnNode> & { childCount?: number },
): EnrichedColumnNode {
  const base = createColumnNode(overrides);
  const children = base.children?.map((child) => createEnrichedElement(child)) ?? null;
  const childSortableIds = children?.map((c) => c.sortableId) ?? [];

  return {
    ...base,
    children,
    childSortableIds,
    sortableId: buildDraggableId('column', base.id),
    isCollapsed: false,
    toggle: vi.fn(),
  };
}

export function createEnrichedRow(
  overrides?: Partial<RowNode> & { columnCount?: number },
): EnrichedRowNode {
  const base = createRowNode(overrides);
  const children = base.children?.map((child) => createEnrichedColumn(child)) ?? null;
  const childSortableIds = children?.map((c) => c.sortableId) ?? [];

  return {
    ...base,
    children,
    childSortableIds,
    sortableId: buildDraggableId('row', base.id),
    isCollapsed: false,
    toggle: vi.fn(),
  };
}

export function createEnrichedSection(
  overrides?: Partial<SectionNode> & { rowCount?: number },
): EnrichedSectionNode {
  const base = createSectionNode(overrides);
  const children = base.children?.map((child) => createEnrichedRow(child)) ?? null;
  const childSortableIds = children?.map((c) => c.sortableId) ?? [];

  return {
    ...base,
    children,
    childSortableIds,
    sortableId: buildDraggableId('section', base.id),
    isCollapsed: false,
    toggle: vi.fn(),
  };
}
