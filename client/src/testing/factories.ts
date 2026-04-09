import type {
  BlockSchema,
  ColumnNode,
  ElementTreeResponse,
  GridSettings,
  RowNode,
  SectionNode,
  SimpleElementNode,
  TreeApiResponse,
  ViewportSettings,
} from '@/types/elements';

let nextId = 1;

export function resetIdCounter(start = 1): void {
  nextId = start;
}

function id(): number {
  return nextId++;
}

function defaultBlockSchema(typeName: string): BlockSchema {
  return {
    typeName,
    label: typeName,
    icon: `font-icon-${typeName.toLowerCase()}`,
    type: typeName,
    title: typeName,
    summary: '',
  };
}

function defaultViewportSettings(overrides?: Partial<ViewportSettings>): ViewportSettings {
  return {
    width: 12,
    offset: 0,
    visible: true,
    ...overrides,
  };
}

function defaultGridSettings(overrides?: Partial<GridSettings>): GridSettings {
  return {
    default: defaultViewportSettings(overrides?.default),
    overrides: overrides?.overrides ?? {},
  };
}

export function createSimpleElement(overrides?: Partial<SimpleElementNode>): SimpleElementNode {
  const elementId = overrides?.id ?? id();
  return {
    id: elementId,
    parentId: overrides?.parentId ?? 100,
    title: overrides?.title ?? `Element ${elementId}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Content'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: `/admin/pages/edit/show/${elementId}`,
    statusFlags: {},
    ...overrides,
  };
}

export function createColumnNode(
  overrides?: Partial<ColumnNode> & { childCount?: number },
): ColumnNode {
  const columnId = overrides?.id ?? id();
  const childCount = overrides?.childCount ?? 1;

  const children: SimpleElementNode[] | null =
    overrides?.children !== undefined
      ? overrides.children
      : childCount > 0
        ? Array.from({ length: childCount }, () => createSimpleElement({ parentId: columnId }))
        : null;

  return {
    id: columnId,
    parentId: overrides?.parentId ?? 100,
    title: overrides?.title ?? `Column ${columnId}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Column'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'column',
    allowedTypes: overrides?.allowedTypes ?? null,
    gridSettings: defaultGridSettings(overrides?.gridSettings),
    ...overrides,
    children,
  };
}

export function createRowNode(overrides?: Partial<RowNode> & { columnCount?: number }): RowNode {
  const rowId = overrides?.id ?? id();
  const columnCount = overrides?.columnCount ?? 1;

  const children: ColumnNode[] | null =
    overrides?.children !== undefined
      ? overrides.children
      : columnCount > 0
        ? Array.from({ length: columnCount }, () => createColumnNode({ parentId: rowId }))
        : null;

  return {
    id: rowId,
    parentId: overrides?.parentId ?? 100,
    title: overrides?.title ?? `Row ${rowId}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Row'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'row',
    allowedTypes: overrides?.allowedTypes ?? null,
    ...overrides,
    children,
  };
}

export function createSectionNode(
  overrides?: Partial<SectionNode> & { rowCount?: number },
): SectionNode {
  const sectionId = overrides?.id ?? id();
  const rowCount = overrides?.rowCount ?? 1;

  const children: RowNode[] | null =
    overrides?.children !== undefined
      ? overrides.children
      : rowCount > 0
        ? Array.from({ length: rowCount }, () => createRowNode({ parentId: sectionId }))
        : null;

  return {
    id: sectionId,
    parentId: overrides?.parentId ?? 1,
    title: overrides?.title ?? `Section ${sectionId}`,
    blockSchema: overrides?.blockSchema ?? defaultBlockSchema('Section'),
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'section',
    allowedTypes: overrides?.allowedTypes ?? null,
    ...overrides,
    children,
  };
}

/**
 * Build a full ElementTreeResponse keyed by root key (default: "1").
 */
export function createTree(sections?: SectionNode[], rootKey = '1'): ElementTreeResponse {
  return {
    [rootKey]: sections ?? [createSectionNode()],
  };
}

export function createTreeApiResponse(overrides?: Partial<TreeApiResponse>): TreeApiResponse {
  return {
    tree: overrides?.tree ?? createTree(),
    overrideCounts: overrides?.overrideCounts ?? {},
  };
}
