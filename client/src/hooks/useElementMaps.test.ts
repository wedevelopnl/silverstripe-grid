import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  createSimpleElement,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories';
import { buildMaps, useElementMaps } from './useElementMaps';
import { buildNodeKey } from '@/types/identity';

describe('buildMaps', () => {
  it('indexes every node by composite NodeKey and exposes sibling arrays', () => {
    resetIdCounter();

    const element = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
    const column = createColumnNode({
      id: 30,
      parent: { type: 'row', id: 20 },
      children: [element],
    });
    const row = createRowNode({
      id: 20,
      parent: { type: 'section', id: 1 },
      children: [column],
    });
    const section = createSectionNode({
      id: 1,
      parent: { type: 'page', id: 99 },
      children: [row],
    });
    const tree = createTreeApiResponse({ pageId: 99, sections: [section] });

    const { nodeMap, childrenByParentKey } = buildMaps(tree);

    // Every grid node indexed by its composite key.
    expect(nodeMap.get(buildNodeKey('section', 1))).toBe(section);
    expect(nodeMap.get(buildNodeKey('row', 20))).toBe(row);
    expect(nodeMap.get(buildNodeKey('column', 30))).toBe(column);
    expect(nodeMap.get(buildNodeKey('element', 10))).toBe(element);

    // Pages are NOT stored in nodeMap — they only live as parent keys.
    expect(nodeMap.has(buildNodeKey('page', 99))).toBe(false);

    // Root entry is keyed by the page's composite key.
    expect(childrenByParentKey.get(buildNodeKey('page', 99))).toEqual([section]);

    // Container children indexed by the container's composite key.
    expect(childrenByParentKey.get(buildNodeKey('section', 1))).toEqual([row]);
    expect(childrenByParentKey.get(buildNodeKey('row', 20))).toEqual([column]);
    expect(childrenByParentKey.get(buildNodeKey('column', 30))).toEqual([element]);
  });

  /**
   * Regression for the polymorphic parent ID collision bug. Before the fix,
   * the root key and the section's ID both collapsed to the number `1`,
   * so `walkNodes` overwrote the page→sections entry with section→rows.
   * With NodeKey-keyed maps the two spaces never meet.
   */
  it('keeps page and section entries separate when their numeric IDs collide', () => {
    resetIdCounter();

    const section1 = createSectionNode({
      id: 1,
      parent: { type: 'page', id: 1 },
      rowCount: 1,
    });
    const section2 = createSectionNode({
      id: 2,
      parent: { type: 'page', id: 1 },
      rowCount: 1,
    });

    const tree = createTreeApiResponse({ pageId: 1, sections: [section1, section2] });
    const { nodeMap, childrenByParentKey } = buildMaps(tree);

    // The section with id=1 must NOT shadow the page with id=1.
    const pageKey = buildNodeKey('page', 1);
    const sectionKey = buildNodeKey('section', 1);
    expect(pageKey).not.toBe(sectionKey);

    // Page entry contains BOTH sections — not overwritten by section 1's rows.
    expect(childrenByParentKey.get(pageKey)).toEqual([section1, section2]);

    // Section 1's own children entry is untouched.
    expect(childrenByParentKey.get(sectionKey)).toEqual(section1.children);

    // nodeMap lookups return the correct node despite the numeric collision.
    expect(nodeMap.get(sectionKey)).toBe(section1);
    expect(nodeMap.get(buildNodeKey('section', 2))).toBe(section2);
  });

  it('handles containers with null children gracefully', () => {
    const section = createSectionNode({ id: 5, children: null });
    const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

    const { nodeMap, childrenByParentKey } = buildMaps(tree);

    expect(nodeMap.get(buildNodeKey('section', 5))).toBe(section);
    expect(childrenByParentKey.has(buildNodeKey('section', 5))).toBe(false);
  });

  it('handles an empty tree', () => {
    const tree = createTreeApiResponse({ pageId: 1, sections: [] });
    const { nodeMap, childrenByParentKey } = buildMaps(tree);

    expect(nodeMap.size).toBe(0);
    // Only the root entry (pointing at an empty array) exists.
    expect(childrenByParentKey.get(buildNodeKey('page', 1))).toEqual([]);
  });
});

describe('useElementMaps', () => {
  it('memoises when tree reference is the same', () => {
    const tree = createTreeApiResponse();

    const { result, rerender } = renderHook(({ t }) => useElementMaps(t), {
      initialProps: { t: tree },
    });

    const first = result.current;
    rerender({ t: tree });
    const second = result.current;

    expect(first).toBe(second);
  });

  it('rebuilds when tree reference changes', () => {
    const treeA = createTreeApiResponse();
    const treeB = createTreeApiResponse();

    const { result, rerender } = renderHook(({ t }) => useElementMaps(t), {
      initialProps: { t: treeA },
    });

    const first = result.current;
    rerender({ t: treeB });
    const second = result.current;

    expect(first).not.toBe(second);
  });
});
