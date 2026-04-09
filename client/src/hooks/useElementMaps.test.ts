import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  createSimpleElement,
  createTree,
  resetIdCounter,
} from '@/testing/factories';
import { buildMaps, useElementMaps } from './useElementMaps';

describe('buildMaps', () => {
  it('should build nodeMap and childrenByParentId from a tree', () => {
    resetIdCounter();

    const element = createSimpleElement({ id: 10, parentId: 30 });
    const column = createColumnNode({
      id: 30,
      parentId: 20,
      children: [element],
    });
    const row = createRowNode({ id: 20, parentId: 1, children: [column] });
    const section = createSectionNode({ id: 1, children: [row] });
    // buildMaps keys childrenByParentId with Number(parentKey),
    // so use a numeric string as the root key
    const tree = createTree([section], '99');

    const { nodeMap, childrenByParentId } = buildMaps(tree);

    // All four nodes indexed by id
    expect(nodeMap.get(1)).toBe(section);
    expect(nodeMap.get(20)).toBe(row);
    expect(nodeMap.get(30)).toBe(column);
    expect(nodeMap.get(10)).toBe(element);

    // Root key mapped to its sections (Number('99') === 99)
    expect(childrenByParentId.get(99)).toEqual([section]);

    // Container children indexed by parent id
    expect(childrenByParentId.get(1)).toEqual([row]);
    expect(childrenByParentId.get(20)).toEqual([column]);
    expect(childrenByParentId.get(30)).toEqual([element]);
  });

  it('should handle containers with null children', () => {
    const section = createSectionNode({ id: 5, children: null });
    const tree = createTree([section]);

    const { nodeMap, childrenByParentId } = buildMaps(tree);

    expect(nodeMap.get(5)).toBe(section);
    // Section has null children so no entry for its id as parent
    expect(childrenByParentId.has(5)).toBe(false);
  });

  it('should handle empty tree', () => {
    const { nodeMap, childrenByParentId } = buildMaps({});

    expect(nodeMap.size).toBe(0);
    expect(childrenByParentId.size).toBe(0);
  });
});

describe('useElementMaps', () => {
  it('should memoize when tree reference is the same', () => {
    const tree = createTree();

    const { result, rerender } = renderHook(({ t }) => useElementMaps(t), {
      initialProps: { t: tree },
    });

    const first = result.current;
    rerender({ t: tree });
    const second = result.current;

    expect(first).toBe(second);
  });

  it('should rebuild when tree reference changes', () => {
    const treeA = createTree();
    const treeB = createTree();

    const { result, rerender } = renderHook(({ t }) => useElementMaps(t), {
      initialProps: { t: treeA },
    });

    const first = result.current;
    rerender({ t: treeB });
    const second = result.current;

    expect(first).not.toBe(second);
  });
});
