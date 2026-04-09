import { describe, it, expect, beforeEach } from 'vitest';
import { applyReorder } from '@/utils/applyReorder';
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories';
import type { ContainerNode, ElementNode, ElementTreeResponse } from '@/types/elements';

/** Narrow an ElementNode to ContainerNode for chained `.children` access in tests. */
function asContainer(node: ElementNode): ContainerNode {
  return node as ContainerNode;
}

/** Navigate Section[i] → Row[j] → Column[k] from a tree root, returning a ContainerNode. */
function drillDown(
  tree: ElementTreeResponse,
  rootKey: string,
  ...indices: number[]
): ContainerNode {
  let node: ElementNode = tree[rootKey][indices[0]];
  for (let i = 1; i < indices.length; i++) {
    node = asContainer(node).children![indices[i]];
  }
  return node as ContainerNode;
}

/**
 * Helper to build a tree with a numeric root key so buildMaps can resolve
 * parent IDs via Number(rootKey). The default composite key from createTree
 * produces NaN, which breaks parent lookups in applyReorder.
 */
function buildTree(pageId = 1): {
  tree: ElementTreeResponse;
  pageId: number;
} {
  // Section → Row → Column → 3 content elements
  const section = createSectionNode({
    parentId: pageId,
    children: [
      createRowNode({
        children: [
          createColumnNode({
            childCount: 3,
          }),
        ],
      }),
    ],
  });

  // Patch nested parentIds to be consistent
  const row = section.children![0];
  row.parentId = section.id;
  const column = row.children![0];
  column.parentId = row.id;
  for (const child of column.children!) {
    child.parentId = column.id;
  }

  const tree: ElementTreeResponse = {
    [String(pageId)]: [section],
  };

  return { tree, pageId };
}

describe('applyReorder', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  describe('same-container reorder', () => {
    it('should move an element down (after a later sibling)', () => {
      const { tree } = buildTree();
      const column = drillDown(tree, '1', 0, 0, 0);
      const [elemA, elemB, elemC] = column.children!;

      // Move first element after last (move down)
      const result = applyReorder(tree, elemA.id, column.id, elemC.id);

      expect(result).not.toBe(tree);
      const resultColumn = drillDown(result, '1', 0, 0, 0);
      const ids = resultColumn.children!.map((c: ElementNode) => c.id);
      expect(ids).toEqual([elemB.id, elemC.id, elemA.id]);
    });

    it('should move an element up (afterElementId=null prepends)', () => {
      const { tree } = buildTree();
      const column = drillDown(tree, '1', 0, 0, 0);
      const [elemA, elemB, elemC] = column.children!;

      // Move last element to the front
      const result = applyReorder(tree, elemC.id, column.id, null);

      expect(result).not.toBe(tree);
      const resultColumn = drillDown(result, '1', 0, 0, 0);
      const ids = resultColumn.children!.map((c: ElementNode) => c.id);
      expect(ids).toEqual([elemC.id, elemA.id, elemB.id]);
    });

    it('should return same reference when element is already at target position', () => {
      const { tree } = buildTree();
      const column = drillDown(tree, '1', 0, 0, 0);
      const [elemA, elemB] = column.children!;

      // elemB is already after elemA — no-op
      const result = applyReorder(tree, elemB.id, column.id, elemA.id);

      expect(result).toBe(tree);
    });
  });

  describe('cross-container move', () => {
    it('should move element to a different parent, updating parentId', () => {
      resetIdCounter();
      const section = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                children: [
                  createSimpleElement({ id: 100, parentId: 30 }),
                  createSimpleElement({ id: 101, parentId: 30 }),
                ],
              }),
              createColumnNode({
                id: 31,
                parentId: 20,
                children: [createSimpleElement({ id: 200, parentId: 31 })],
              }),
            ],
          }),
        ],
      });

      const tree: ElementTreeResponse = { '1': [section] };

      // Move element 101 from column 30 to column 31, after element 200
      const result = applyReorder(tree, 101, 31, 200);

      expect(result).not.toBe(tree);

      const resultRow = drillDown(result, '1', 0, 0);
      const col30 = asContainer(resultRow.children![0]);
      const col31 = asContainer(resultRow.children![1]);

      // Removed from source
      expect(col30.children!.map((c: ElementNode) => c.id)).toEqual([100]);
      // Inserted in target after 200
      expect(col31.children!.map((c: ElementNode) => c.id)).toEqual([200, 101]);
      // parentId updated
      expect(col31.children![1].parentId).toBe(31);
    });

    it('should prepend in target when afterElementId is null', () => {
      resetIdCounter();
      const section = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                children: [createSimpleElement({ id: 100, parentId: 30 })],
              }),
              createColumnNode({
                id: 31,
                parentId: 20,
                children: [createSimpleElement({ id: 200, parentId: 31 })],
              }),
            ],
          }),
        ],
      });

      const tree: ElementTreeResponse = { '1': [section] };

      // Move element 100 to column 31, prepend (null)
      const result = applyReorder(tree, 100, 31, null);

      const resultRow = drillDown(result, '1', 0, 0);
      const col31 = asContainer(resultRow.children![1]);

      expect(col31.children!.map((c: ElementNode) => c.id)).toEqual([100, 200]);
      expect(col31.children![0].parentId).toBe(31);
    });
  });

  describe('reference preservation', () => {
    it('should preserve object references for unaffected root trees', () => {
      resetIdCounter();

      const section1 = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                childCount: 2,
              }),
            ],
          }),
        ],
      });
      // Fix auto-generated parentIds for column children
      for (const child of section1.children![0].children![0].children!) {
        child.parentId = 30;
      }

      const section2 = createSectionNode({
        id: 11,
        parentId: 2,
        children: [
          createRowNode({
            id: 21,
            parentId: 11,
            children: [
              createColumnNode({
                id: 31,
                parentId: 21,
                childCount: 1,
              }),
            ],
          }),
        ],
      });

      const tree: ElementTreeResponse = {
        '1': [section1],
        '2': [section2],
      };

      // Reorder within root tree '1' — root tree '2' should keep same reference
      const col30Children = section1.children![0].children![0].children!;
      const result = applyReorder(tree, col30Children[1].id, 30, null);

      expect(result).not.toBe(tree);
      // Affected tree is cloned
      expect(result['1']).not.toBe(tree['1']);
      // Unaffected tree keeps same reference
      expect(result['2']).toBe(tree['2']);
    });
  });

  describe('edge cases', () => {
    it('should return original tree when element is not found', () => {
      const { tree } = buildTree();

      const result = applyReorder(tree, 9999, 3, null);

      expect(result).toBe(tree);
    });

    it('should return original tree when source parent has no children in map', () => {
      // Construct a tree where an element claims a parentId that is not in the map
      const orphan = createSimpleElement({ id: 50, parentId: 999 });
      // Manually inject the orphan into the nodeMap by placing it as a root element
      // But since nodeMap only builds from walking, we need a different approach:
      // An element whose parentId does not match any key or container in the tree
      // won't be reachable by buildMaps, so it won't be in the nodeMap at all.
      // This edge case is effectively the same as "element not found".
      const tree: ElementTreeResponse = { '1': [orphan] };

      // Element 50 is in the tree at root level, its parentId is 999
      // buildMaps sets childrenByParentId(1, [orphan]) — source parent is 999 which has no entry
      const result = applyReorder(tree, 50, 1, null);

      expect(result).toBe(tree);
    });

    it('should return original tree when target parent does not exist', () => {
      const { tree } = buildTree();
      const column = drillDown(tree, '1', 0, 0, 0);
      const firstChild = column.children![0];

      const result = applyReorder(tree, firstChild.id, 9999, null);

      expect(result).toBe(tree);
    });

    it('should append to end when afterElementId is not found in target', () => {
      resetIdCounter();
      const section = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                children: [
                  createSimpleElement({ id: 100, parentId: 30 }),
                  createSimpleElement({ id: 101, parentId: 30 }),
                ],
              }),
            ],
          }),
        ],
      });

      const tree: ElementTreeResponse = { '1': [section] };

      // afterElementId 9999 does not exist — should append
      const result = applyReorder(tree, 100, 30, 9999);

      const resultColumn = drillDown(result, '1', 0, 0, 0);
      const ids = resultColumn.children!.map((c: ElementNode) => c.id);
      expect(ids).toEqual([101, 100]);
    });
  });

  describe('multi-root tree isolation', () => {
    it('should only affect root trees containing the source or target parent', () => {
      resetIdCounter();

      // Three root trees: move within root 1, roots 2 and 3 should be unaffected
      const section1 = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                childCount: 2,
              }),
            ],
          }),
        ],
      });
      for (const child of section1.children![0].children![0].children!) {
        child.parentId = 30;
      }

      const section2 = createSectionNode({
        id: 11,
        parentId: 2,
        children: [
          createRowNode({
            id: 21,
            parentId: 11,
            children: [createColumnNode({ id: 31, parentId: 21, childCount: 1 })],
          }),
        ],
      });

      const section3 = createSectionNode({
        id: 12,
        parentId: 3,
        children: [
          createRowNode({
            id: 22,
            parentId: 12,
            children: [createColumnNode({ id: 32, parentId: 22, childCount: 1 })],
          }),
        ],
      });

      const tree: ElementTreeResponse = {
        '1': [section1],
        '2': [section2],
        '3': [section3],
      };

      const col30Children = section1.children![0].children![0].children!;
      const result = applyReorder(tree, col30Children[1].id, 30, null);

      expect(result).not.toBe(tree);
      // Root 1 affected — new reference
      expect(result['1']).not.toBe(tree['1']);
      // Roots 2 and 3 unaffected — same reference
      expect(result['2']).toBe(tree['2']);
      expect(result['3']).toBe(tree['3']);
    });

    it('should mark both source and target root trees as affected in cross-root move', () => {
      resetIdCounter();

      const section1 = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                children: [
                  createSimpleElement({ id: 100, parentId: 30 }),
                  createSimpleElement({ id: 101, parentId: 30 }),
                ],
              }),
            ],
          }),
        ],
      });

      const section2 = createSectionNode({
        id: 11,
        parentId: 2,
        children: [
          createRowNode({
            id: 21,
            parentId: 11,
            children: [
              createColumnNode({
                id: 31,
                parentId: 21,
                children: [createSimpleElement({ id: 200, parentId: 31 })],
              }),
            ],
          }),
        ],
      });

      const section3 = createSectionNode({
        id: 12,
        parentId: 3,
        children: [
          createRowNode({
            id: 22,
            parentId: 12,
            children: [createColumnNode({ id: 32, parentId: 22, childCount: 1 })],
          }),
        ],
      });

      const tree: ElementTreeResponse = {
        '1': [section1],
        '2': [section2],
        '3': [section3],
      };

      // Move element 100 from column 30 (root 1) to column 31 (root 2)
      const result = applyReorder(tree, 100, 31, 200);

      expect(result).not.toBe(tree);
      // Both roots 1 and 2 are affected
      expect(result['1']).not.toBe(tree['1']);
      expect(result['2']).not.toBe(tree['2']);
      // Root 3 is unaffected
      expect(result['3']).toBe(tree['3']);

      // Verify the move happened correctly
      const resultCol30 = asContainer(drillDown(result, '1', 0, 0).children![0]);
      const resultCol31 = asContainer(drillDown(result, '2', 0, 0).children![0]);
      expect(resultCol30.children!.map((c: ElementNode) => c.id)).toEqual([101]);
      expect(resultCol31.children!.map((c: ElementNode) => c.id)).toEqual([200, 100]);
    });
  });

  describe('insertIntoArray fallback', () => {
    it('should append when afterElementId does not exist in a cross-parent move', () => {
      resetIdCounter();
      const section = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                children: [createSimpleElement({ id: 100, parentId: 30 })],
              }),
              createColumnNode({
                id: 31,
                parentId: 20,
                children: [
                  createSimpleElement({ id: 200, parentId: 31 }),
                  createSimpleElement({ id: 201, parentId: 31 }),
                ],
              }),
            ],
          }),
        ],
      });

      const tree: ElementTreeResponse = { '1': [section] };

      // Move element 100 to column 31, after non-existent element 9999 — should append
      const result = applyReorder(tree, 100, 31, 9999);

      const resultCol31 = asContainer(drillDown(result, '1', 0, 0).children![1]);
      const ids = resultCol31.children!.map((c: ElementNode) => c.id);
      expect(ids).toEqual([200, 201, 100]);
    });
  });

  describe('no-op detection', () => {
    it('should return same reference when prepending an element already at first position', () => {
      const { tree } = buildTree();
      const column = drillDown(tree, '1', 0, 0, 0);
      const elemA = column.children![0]; // already first

      const result = applyReorder(tree, elemA.id, column.id, null);

      expect(result).toBe(tree);
    });

    it('should return same reference when inserting after previous sibling matches current position', () => {
      const { tree } = buildTree();
      const column = drillDown(tree, '1', 0, 0, 0);
      const [, elemB, elemC] = column.children!;

      // Element C (index 2) is already after element B (index 1)
      const result = applyReorder(tree, elemC.id, column.id, elemB.id);

      expect(result).toBe(tree);
    });

    it('should not treat cross-container move as no-op even if positions match', () => {
      resetIdCounter();
      const section = createSectionNode({
        id: 10,
        parentId: 1,
        children: [
          createRowNode({
            id: 20,
            parentId: 10,
            children: [
              createColumnNode({
                id: 30,
                parentId: 20,
                children: [createSimpleElement({ id: 100, parentId: 30 })],
              }),
              createColumnNode({
                id: 31,
                parentId: 20,
                children: [createSimpleElement({ id: 200, parentId: 31 })],
              }),
            ],
          }),
        ],
      });

      const tree: ElementTreeResponse = { '1': [section] };

      // Move 100 to column 31 — different parent, so never a no-op
      const result = applyReorder(tree, 100, 31, null);

      expect(result).not.toBe(tree);
      const resultRow = drillDown(result, '1', 0, 0);
      expect(asContainer(resultRow.children![0]).children!).toHaveLength(0);
      expect(asContainer(resultRow.children![1]).children!.map((c: ElementNode) => c.id)).toEqual([
        100, 200,
      ]);
    });
  });
});
