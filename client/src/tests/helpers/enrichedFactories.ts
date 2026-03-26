import { vi } from 'vitest';
import type {
  EnrichedSimpleElementNode,
  EnrichedColumnNode,
  EnrichedRowNode,
  EnrichedSectionNode,
} from '@/types/enriched';

export function makeEnrichedElement(
  overrides: Partial<EnrichedSimpleElementNode> = {},
): EnrichedSimpleElementNode {
  const id = overrides.id ?? 1;
  return {
    id,
    parentId: 100,
    title: 'My Element',
    blockSchema: {
      typeName: 'Content',
      label: 'Content',
      icon: 'font-icon-block-content',
      type: 'Content',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    sortableId: `element-${id}`,
    ...overrides,
  };
}

export function makeEnrichedColumn(
  overrides: Partial<EnrichedColumnNode> = {},
): EnrichedColumnNode {
  const id = overrides.id ?? 10;
  const children = overrides.children ?? null;
  return {
    id,
    parentId: 200,
    title: 'Column',
    blockSchema: {
      typeName: String.raw`WeDevelop\Grid\Elements\Column`,
      label: 'Column',
      icon: 'font-icon-block-content',
      type: 'Column',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'column',
    allowedTypes: null,
    gridSettings: {
      default: { width: 12, offset: 0, visible: true },
      overrides: {},
    },
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `column-${id}`,
    children,
    childSortableIds: children?.map((c) => c.sortableId) ?? [],
    ...overrides,
  };
}

export function makeEnrichedRow(
  overrides: Partial<EnrichedRowNode> = {},
): EnrichedRowNode {
  const id = overrides.id ?? 20;
  const children = overrides.children ?? null;
  return {
    id,
    parentId: 300,
    title: 'Row',
    blockSchema: {
      typeName: String.raw`WeDevelop\Grid\Elements\Row`,
      label: 'Row',
      icon: 'font-icon-block-content',
      type: 'Row',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'row',
    allowedTypes: null,
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `row-${id}`,
    children,
    childSortableIds: children?.map((c) => c.sortableId) ?? [],
    ...overrides,
  };
}

export function makeEnrichedSection(
  overrides: Partial<EnrichedSectionNode> = {},
): EnrichedSectionNode {
  const id = overrides.id ?? 1;
  const children = overrides.children ?? null;
  return {
    id,
    parentId: 42,
    title: 'Section',
    blockSchema: {
      typeName: String.raw`WeDevelop\Grid\Elements\Section`,
      label: 'Section',
      icon: 'font-icon-block-content',
      type: 'Section',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'section',
    allowedTypes: null,
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `section-${id}`,
    children,
    childSortableIds: children?.map((c) => c.sortableId) ?? [],
    ...overrides,
  };
}
