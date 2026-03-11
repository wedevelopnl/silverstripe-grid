import type {
  SimpleElementNode,
  ColumnNode,
  RowNode,
  SectionNode,
} from '@/types/elements';

export function makeElement(id: number, parentId: number): SimpleElementNode {
  return {
    id,
    parentId,
    title: `Element ${id}`,
    blockSchema: {
      typeName: 'Element',
      label: 'Element',
      icon: 'font-icon-block-content',
      type: 'Element',
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
  };
}

export function makeColumn(
  id: number,
  children: SimpleElementNode[],
  parentId: number,
): ColumnNode {
  return {
    id,
    parentId,
    title: `Column ${id}`,
    blockSchema: {
      typeName: 'Column',
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
    children,
    gridSettings: { md: { width: 6, offset: 0, visible: true } },
  };
}

export function makeRow(
  id: number,
  children: ColumnNode[],
  parentId: number,
): RowNode {
  return {
    id,
    parentId,
    title: `Row ${id}`,
    blockSchema: {
      typeName: 'Row',
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
    children,
  };
}

export function makeSection(
  id: number,
  children: RowNode[],
  parentId: number,
): SectionNode {
  return {
    id,
    parentId,
    title: `Section ${id}`,
    blockSchema: {
      typeName: 'Section',
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
    children,
  };
}
