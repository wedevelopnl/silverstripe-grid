import { countDescendants } from '@/utils/countDescendants';
import type { ColumnNode } from '@/types/elements';
import { makeElement, makeColumn, makeRow, makeSection } from '../helpers/elementFactories';

describe('countDescendants', () => {
  it('returns 0 for a leaf element', () => {
    expect(countDescendants(makeElement(1, 1))).toBe(0);
  });

  it('returns 0 for a container with null children', () => {
    const column: ColumnNode = { ...makeColumn(1, [], 1), children: null };
    expect(countDescendants(column)).toBe(0);
  });

  it('returns 0 for a container with empty children', () => {
    expect(countDescendants(makeColumn(1, [], 1))).toBe(0);
  });

  it('counts direct children of a column', () => {
    const column = makeColumn(1, [makeElement(2, 1), makeElement(3, 1)], 1);
    expect(countDescendants(column)).toBe(2);
  });

  it('counts nested descendants in a full section tree', () => {
    const section = makeSection(1, [
      makeRow(2, [
        makeColumn(3, [makeElement(4, 3), makeElement(5, 3)], 2),
        makeColumn(6, [makeElement(7, 6)], 2),
      ], 1),
    ], 1);
    // row(1) + column(2) + leaf(3) + column(1) + leaf(1) = 6
    expect(countDescendants(section)).toBe(6);
  });

  it('counts deeply nested structure correctly', () => {
    const section = makeSection(1, [
      makeRow(2, [
        makeColumn(3, [makeElement(4, 3)], 2),
      ], 1),
      makeRow(5, [
        makeColumn(6, [], 5),
      ], 1),
    ], 1);
    // row + column + leaf + row + column = 5
    expect(countDescendants(section)).toBe(5);
  });
});
