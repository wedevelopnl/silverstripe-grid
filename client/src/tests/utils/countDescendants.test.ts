import { countDescendants } from '@/utils/countDescendants';
import type { SimpleElementNode, ColumnNode, RowNode, SectionNode } from '@/types/elements';

function makeLeaf(id: number): SimpleElementNode {
  return {
    id,
    parentId: 1,
    title: `Leaf ${id}`,
    blockSchema: { typeName: 'Content', label: 'Content', icon: '', type: 'Content', title: `Leaf ${id}`, summary: '' },
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

function makeColumn(id: number, children: SimpleElementNode[] | null): ColumnNode {
  return {
    ...makeLeaf(id),
    containerType: 'column' as const,
    allowedTypes: null,
    children,
    gridSettings: {},
  };
}

function makeRow(id: number, children: ColumnNode[] | null): RowNode {
  return {
    ...makeLeaf(id),
    containerType: 'row' as const,
    allowedTypes: null,
    children,
  };
}

function makeSection(id: number, children: RowNode[] | null): SectionNode {
  return {
    ...makeLeaf(id),
    containerType: 'section' as const,
    allowedTypes: null,
    children,
  };
}

describe('countDescendants', () => {
  it('returns 0 for a leaf element', () => {
    expect(countDescendants(makeLeaf(1))).toBe(0);
  });

  it('returns 0 for a container with null children', () => {
    expect(countDescendants(makeColumn(1, null))).toBe(0);
  });

  it('returns 0 for a container with empty children', () => {
    expect(countDescendants(makeColumn(1, []))).toBe(0);
  });

  it('counts direct children of a column', () => {
    const column = makeColumn(1, [makeLeaf(2), makeLeaf(3)]);
    expect(countDescendants(column)).toBe(2);
  });

  it('counts nested descendants in a full section tree', () => {
    const section = makeSection(1, [
      makeRow(2, [
        makeColumn(3, [makeLeaf(4), makeLeaf(5)]),
        makeColumn(6, [makeLeaf(7)]),
      ]),
    ]);
    // row(1) + column(2) + leaf(3) + column(1) + leaf(1) = 6
    expect(countDescendants(section)).toBe(6);
  });

  it('counts deeply nested structure correctly', () => {
    const section = makeSection(1, [
      makeRow(2, [
        makeColumn(3, [makeLeaf(4)]),
      ]),
      makeRow(5, [
        makeColumn(6, []),
      ]),
    ]);
    // row + column + leaf + row + column = 5
    expect(countDescendants(section)).toBe(5);
  });
});
