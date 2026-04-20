import { describe, it, expect } from 'vitest';
import { resolveReorderParams } from './resolveReorderParams';
import type { ElementMaps } from '@/hooks/useElementMaps';
import { createColumnNode, createRowNode, createSimpleElement } from '@/testing/factories';
import type { ElementNode } from '@/types/elements';
import { buildNodeKey, type NodeKey } from '@/types/identity';

function mapsFrom(nodes: ElementNode[]): ElementMaps {
  const nodeMap = new Map<NodeKey, ElementNode>();
  for (const node of nodes) {
    nodeMap.set(node.nodeKey, node);
  }
  return {
    nodeMap,
    childrenByParentKey: new Map(),
  };
}

describe('resolveReorderParams', () => {
  it('returns params for a valid same-container reorder', () => {
    const row20 = createRowNode({ id: 20, parent: { type: 'section', id: 5 } });
    const row30 = createRowNode({ id: 30, parent: { type: 'section', id: 5 } });
    const result = resolveReorderParams({
      activeId: buildNodeKey('row', 10),
      targetParent: { type: 'section', id: 5 },
      targetParentKey: buildNodeKey('section', 5),
      overIndex: 2,
      containerItems: [row20.nodeKey, row30.nodeKey, buildNodeKey('row', 10)],
      sourceParentKey: buildNodeKey('section', 5),
      sourceIndex: 0,
      maps: mapsFrom([row20, row30]),
    });

    expect(result).toEqual({
      element: { type: 'row', id: 10 },
      parent: { type: 'section', id: 5 },
      after: { type: 'row', id: 30 },
    });
  });

  it('returns null for no-op (same container, same index)', () => {
    const row10 = createRowNode({ id: 10 });
    const row20 = createRowNode({ id: 20 });
    const result = resolveReorderParams({
      activeId: buildNodeKey('row', 10),
      targetParent: { type: 'section', id: 5 },
      targetParentKey: buildNodeKey('section', 5),
      overIndex: 0,
      containerItems: [row10.nodeKey, row20.nodeKey],
      sourceParentKey: buildNodeKey('section', 5),
      sourceIndex: 0,
      maps: mapsFrom([row10, row20]),
    });

    expect(result).toBeNull();
  });

  it('returns null for unparseable active id', () => {
    const result = resolveReorderParams({
      // Intentionally malformed — the function must reject values that don't
      // match the `${NodeType}-${number}` shape even when typed as NodeKey.
      activeId: 'invalid' as NodeKey,
      targetParent: { type: 'section', id: 5 },
      targetParentKey: buildNodeKey('section', 5),
      overIndex: 0,
      containerItems: ['invalid' as NodeKey, buildNodeKey('row', 20)],
      sourceParentKey: buildNodeKey('section', 5),
      sourceIndex: 1,
      maps: mapsFrom([]),
    });

    expect(result).toBeNull();
  });

  it('resolves after as null when inserting at index 0', () => {
    const col4 = createColumnNode({ id: 4 });
    const col5 = createColumnNode({ id: 5 });
    const result = resolveReorderParams({
      activeId: buildNodeKey('column', 3),
      targetParent: { type: 'row', id: 10 },
      targetParentKey: buildNodeKey('row', 10),
      overIndex: 0,
      containerItems: [buildNodeKey('column', 3), col4.nodeKey, col5.nodeKey],
      sourceParentKey: buildNodeKey('row', 20),
      sourceIndex: 0,
      maps: mapsFrom([col4, col5]),
    });

    expect(result).toEqual({
      element: { type: 'column', id: 3 },
      parent: { type: 'row', id: 10 },
      after: null,
    });
  });

  it('skips active element when resolving after', () => {
    const element2 = createSimpleElement({ id: 2 });
    const element3 = createSimpleElement({ id: 3 });
    const result = resolveReorderParams({
      activeId: buildNodeKey('element', 1),
      targetParent: { type: 'column', id: 100 },
      targetParentKey: buildNodeKey('column', 100),
      overIndex: 2,
      containerItems: [
        element2.nodeKey,
        buildNodeKey('element', 1),
        buildNodeKey('element', 1),
        element3.nodeKey,
      ],
      sourceParentKey: buildNodeKey('column', 200),
      sourceIndex: 0,
      maps: mapsFrom([element2, element3]),
    });

    expect(result).toEqual({
      element: { type: 'element', id: 1 },
      parent: { type: 'column', id: 100 },
      after: { type: 'element', id: 2 },
    });
  });

  it('handles cross-container move', () => {
    const element10 = createSimpleElement({ id: 10 });
    const result = resolveReorderParams({
      activeId: buildNodeKey('element', 5),
      targetParent: { type: 'column', id: 50 },
      targetParentKey: buildNodeKey('column', 50),
      overIndex: 1,
      containerItems: [element10.nodeKey, buildNodeKey('element', 5)],
      sourceParentKey: buildNodeKey('column', 30),
      sourceIndex: 0,
      maps: mapsFrom([element10]),
    });

    expect(result).toEqual({
      element: { type: 'element', id: 5 },
      parent: { type: 'column', id: 50 },
      after: { type: 'element', id: 10 },
    });
  });
});
