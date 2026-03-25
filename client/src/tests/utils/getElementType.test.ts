import { getElementType } from '@/utils/getElementType';
import type { SectionNode, RowNode, ColumnNode, SimpleElementNode } from '@/types/elements';

function makeBase() {
  return {
    id: 1,
    parentId: 100,
    title: 'Test',
    blockSchema: { typeName: 'Test', label: 'Test', icon: '', type: 'Test', title: '', summary: '' },
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

describe('getElementType', () => {
  it('returns "section" for a section node', () => {
    const node: SectionNode = {
      ...makeBase(),
      containerType: 'section',
      allowedTypes: null,
      children: null,
    };

    expect(getElementType(node)).toBe('section');
  });

  it('returns "row" for a row node', () => {
    const node: RowNode = {
      ...makeBase(),
      containerType: 'row',
      allowedTypes: null,
      children: null,
    };

    expect(getElementType(node)).toBe('row');
  });

  it('returns "column" for a column node', () => {
    const node: ColumnNode = {
      ...makeBase(),
      containerType: 'column',
      allowedTypes: null,
      children: null,
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: {} },
    };

    expect(getElementType(node)).toBe('column');
  });

  it('returns "element" for a simple element node', () => {
    const node: SimpleElementNode = makeBase();

    expect(getElementType(node)).toBe('element');
  });
});
