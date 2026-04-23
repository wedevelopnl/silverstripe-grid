import { describe, it, expect } from 'vitest';
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories';
import type { ColumnNode, RowNode, SectionNode, SimpleElementNode } from '@/types/elements';
import { NodeIdentity } from '@/types/identity';
import { applyReorder } from './applyReorder';

describe('applyReorder', () => {
  describe('same-container reorder', () => {
    it('moves an element to a later position within the same parent', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const e2 = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const e3 = createSimpleElement({ id: 12, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [e1, e2, e3] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 10),
        NodeIdentity.toKey('column', 30),
        NodeIdentity.toKey('element', 12),
      );

      const movedColumn = ((result.nodes[0] as SectionNode).children?.[0] as RowNode)
        .children?.[0] as ColumnNode;
      expect(movedColumn.children?.map((c: SimpleElementNode) => c.id)).toEqual([11, 12, 10]);
    });

    it('prepends when afterKey is null', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const e2 = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [e1, e2] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 11),
        NodeIdentity.toKey('column', 30),
        null,
      );

      const movedColumn = ((result.nodes[0] as SectionNode).children?.[0] as RowNode)
        .children?.[0] as ColumnNode;
      expect(movedColumn.children?.map((c: SimpleElementNode) => c.id)).toEqual([11, 10]);
    });

    it('returns the same reference when the element is already at the target position', () => {
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const e2 = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [e1, e2] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 10),
        NodeIdentity.toKey('column', 30),
        null,
      );

      expect(result).toBe(tree);
    });
  });

  describe('cross-container move', () => {
    it('moves an element to a different column and updates parent fields', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const col30 = createColumnNode({ id: 30, children: [e1] });
      const col31 = createColumnNode({ id: 31, children: [] });
      const row = createRowNode({ id: 20, children: [col30, col31] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 10),
        NodeIdentity.toKey('column', 31),
        null,
      );

      const row20 = (result.nodes[0] as SectionNode).children?.[0] as RowNode;
      const movedCol30 = row20.children?.[0] as ColumnNode;
      const movedCol31 = row20.children?.[1] as ColumnNode;
      expect(movedCol30.children).toEqual([]);
      expect(movedCol31.children?.[0].id).toBe(10);
      expect(movedCol31.children?.[0].parent).toEqual({ type: 'column', id: 31 });
      expect(movedCol31.children?.[0].parentKey).toBe(NodeIdentity.toKey('column', 31));
    });
  });

  describe('collision regression', () => {
    it('reorders sections correctly when section id equals page id', () => {
      resetIdCounter();
      const section1 = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        rowCount: 0,
      });
      const section2 = createSectionNode({
        id: 2,
        parent: { type: 'page', id: 1 },
        rowCount: 0,
      });

      const tree = createTreeApiResponse({
        pageId: 1,
        sections: [section1, section2],
      });

      // Move section 1 after section 2 (page id and section id=1 share `1`).
      const result = applyReorder(
        tree,
        NodeIdentity.toKey('section', 1),
        NodeIdentity.toKey('page', 1),
        NodeIdentity.toKey('section', 2),
      );

      expect(result.nodes.map((s) => s.id)).toEqual([2, 1]);
    });

    it('moves a row across sections when numeric IDs collide', () => {
      resetIdCounter();
      const row10 = createRowNode({
        id: 10,
        parent: { type: 'section', id: 1 },
        columnCount: 0,
      });
      const section1 = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row10],
      });
      const section2 = createSectionNode({
        id: 2,
        parent: { type: 'page', id: 1 },
        children: [],
      });

      const tree = createTreeApiResponse({
        pageId: 1,
        sections: [section1, section2],
      });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('row', 10),
        NodeIdentity.toKey('section', 2),
        null,
      );

      const [movedSection1, movedSection2] = result.nodes as [SectionNode, SectionNode];
      expect(movedSection1.children).toEqual([]);
      expect(movedSection2.children).toHaveLength(1);
      expect(movedSection2.children?.[0].id).toBe(10);
      expect(movedSection2.children?.[0].parent).toEqual({ type: 'section', id: 2 });
    });
  });

  describe('edge cases', () => {
    it('returns the original tree when the element is not found', () => {
      const tree = createTreeApiResponse({ pageId: 1 });
      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 9999),
        NodeIdentity.toKey('column', 30),
        null,
      );
      expect(result).toBe(tree);
    });

    it('returns the original tree when the target parent is not in the maps', () => {
      const tree = createTreeApiResponse({ pageId: 1 });
      const firstSection = tree.nodes[0] as SectionNode;
      const result = applyReorder(
        tree,
        firstSection.nodeKey,
        NodeIdentity.toKey('page', 9999),
        null,
      );
      expect(result).toBe(tree);
    });

    it('appends to the end when afterKey is not found in the target', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const col30 = createColumnNode({ id: 30, children: [e1] });
      const col31 = createColumnNode({
        id: 31,
        children: [createSimpleElement({ id: 20, parent: { type: 'column', id: 31 } })],
      });
      const row = createRowNode({ id: 40, children: [col30, col31] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 10),
        NodeIdentity.toKey('column', 31),
        NodeIdentity.toKey('element', 9999),
      );

      const row40 = (result.nodes[0] as SectionNode).children?.[0] as RowNode;
      const col31Result = row40.children?.[1] as ColumnNode;
      expect(col31Result.children?.map((c: SimpleElementNode) => c.id)).toEqual([20, 10]);
    });
  });

  describe('no-op detection', () => {
    it('returns the same reference when moving an element adjacent-after its current position', () => {
      // sourceChildren = [A, B, C]. Moving C with afterKey = B is a no-op because
      // C is already directly after B. The isNoOp check `afterIndex + 1 === sourceIndex`
      // (1 + 1 === 2) must return true. This distinguishes the default predicate from
      // mutants that alter the findIndex callback or the afterIndex comparison.
      resetIdCounter();
      const a = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const b = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const c = createSimpleElement({ id: 12, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [a, b, c] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 12),
        NodeIdentity.toKey('column', 30),
        NodeIdentity.toKey('element', 11),
      );

      expect(result).toBe(tree);
    });

    it('applies the move when sourceIndex === 0 and afterKey is not a sibling', () => {
      // Source at index 0 with an unknown afterKey exercises the `afterIndex === -1`
      // guard in isNoOp. Default: guard returns false → move applied, appending to end.
      // Mutant `if (false) return false;`: falls through to `afterIndex + 1 === sourceIndex`
      // → `-1 + 1 === 0` → true → wrongly treats as no-op → returns tree unchanged.
      resetIdCounter();
      const a = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const b = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const c = createSimpleElement({ id: 12, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [a, b, c] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 10),
        NodeIdentity.toKey('column', 30),
        NodeIdentity.toKey('element', 9999),
      );

      expect(result).not.toBe(tree);
      const movedColumn = ((result.nodes[0] as SectionNode).children?.[0] as RowNode)
        .children?.[0] as ColumnNode;
      expect(movedColumn.children?.map((c: SimpleElementNode) => c.id)).toEqual([11, 12, 10]);
    });
  });

  describe('insertIntoArray with matching afterKey', () => {
    it('inserts after the matching sibling when afterKey is found with multiple target children', () => {
      // Target column has two existing children [20, 21]. Moving element 10 cross-container
      // with afterKey=20 must position 10 immediately after 20 → [20, 10, 21]. This
      // distinguishes the real predicate from mutants that force findIndex to always
      // return 0, true, or -1 (all of which would yield different orderings).
      resetIdCounter();
      const movedEl = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const col30 = createColumnNode({ id: 30, children: [movedEl] });
      const existing20 = createSimpleElement({ id: 20, parent: { type: 'column', id: 31 } });
      const existing21 = createSimpleElement({ id: 21, parent: { type: 'column', id: 31 } });
      const col31 = createColumnNode({ id: 31, children: [existing20, existing21] });
      const row = createRowNode({ id: 40, children: [col30, col31] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        NodeIdentity.toKey('element', 10),
        NodeIdentity.toKey('column', 31),
        NodeIdentity.toKey('element', 20),
      );

      const row40 = (result.nodes[0] as SectionNode).children?.[0] as RowNode;
      const col31Result = row40.children?.[1] as ColumnNode;
      expect(col31Result.children?.map((c: SimpleElementNode) => c.id)).toEqual([20, 10, 21]);
    });
  });

});
