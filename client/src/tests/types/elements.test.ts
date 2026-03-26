import {
  isContainerNode,
  isSectionNode,
  isRowNode,
  isColumnNode,
  isSimpleElementNode,
} from '@/types/elements';
import { makeLeaf, makeColumn, makeRow, makeSection } from '../helpers/elementFactories';

describe('type guards', () => {
  const simple = makeLeaf();
  const column = makeColumn(10);
  const row = makeRow(20);
  const section = makeSection(30);

  describe('isContainerNode', () => {
    it('returns true for container nodes', () => {
      expect(isContainerNode(section)).toBe(true);
      expect(isContainerNode(row)).toBe(true);
      expect(isContainerNode(column)).toBe(true);
    });

    it('returns false for simple elements', () => {
      expect(isContainerNode(simple)).toBe(false);
    });
  });

  describe('isSectionNode', () => {
    it('returns true only for sections', () => {
      expect(isSectionNode(section)).toBe(true);
      expect(isSectionNode(row)).toBe(false);
      expect(isSectionNode(column)).toBe(false);
      expect(isSectionNode(simple)).toBe(false);
    });
  });

  describe('isRowNode', () => {
    it('returns true only for rows', () => {
      expect(isRowNode(row)).toBe(true);
      expect(isRowNode(section)).toBe(false);
      expect(isRowNode(column)).toBe(false);
      expect(isRowNode(simple)).toBe(false);
    });
  });

  describe('isColumnNode', () => {
    it('returns true only for columns', () => {
      expect(isColumnNode(column)).toBe(true);
      expect(isColumnNode(section)).toBe(false);
      expect(isColumnNode(row)).toBe(false);
      expect(isColumnNode(simple)).toBe(false);
    });
  });

  describe('isSimpleElementNode', () => {
    it('returns true only for simple elements', () => {
      expect(isSimpleElementNode(simple)).toBe(true);
      expect(isSimpleElementNode(section)).toBe(false);
      expect(isSimpleElementNode(row)).toBe(false);
      expect(isSimpleElementNode(column)).toBe(false);
    });
  });

});
