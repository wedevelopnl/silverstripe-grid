import { describe, it, expect } from 'vitest';
import { resolveReorderParams } from './resolveReorderParams';

describe('resolveReorderParams', () => {
  it('returns params for a valid same-container reorder', () => {
    const result = resolveReorderParams({
      activeId: 'row-10',
      overContainerParentId: 5,
      overIndex: 2,
      containerItems: ['row-20', 'row-30', 'row-10'],
      sourceContainerParentId: 5,
      sourceIndex: 0,
    });

    expect(result).toEqual({
      elementID: 10,
      targetParentId: 5,
      afterElementID: 30,
    });
  });

  it('returns null for no-op (same container, same index)', () => {
    const result = resolveReorderParams({
      activeId: 'row-10',
      overContainerParentId: 5,
      overIndex: 0,
      containerItems: ['row-10', 'row-20'],
      sourceContainerParentId: 5,
      sourceIndex: 0,
    });

    expect(result).toBeNull();
  });

  it('returns null for unparseable active id', () => {
    const result = resolveReorderParams({
      activeId: 'invalid',
      overContainerParentId: 5,
      overIndex: 0,
      containerItems: ['invalid', 'row-20'],
      sourceContainerParentId: 5,
      sourceIndex: 1,
    });

    expect(result).toBeNull();
  });

  it('resolves afterElementID as null when inserting at index 0', () => {
    const result = resolveReorderParams({
      activeId: 'column-3',
      overContainerParentId: 10,
      overIndex: 0,
      containerItems: ['column-3', 'column-4', 'column-5'],
      sourceContainerParentId: 20,
      sourceIndex: 0,
    });

    expect(result).toEqual({
      elementID: 3,
      targetParentId: 10,
      afterElementID: null,
    });
  });

  it('skips active element when resolving afterElementID', () => {
    // Active is at index 2, previous item at index 1 is the active itself
    const result = resolveReorderParams({
      activeId: 'element-1',
      overContainerParentId: 100,
      overIndex: 2,
      containerItems: ['element-2', 'element-1', 'element-1', 'element-3'],
      sourceContainerParentId: 200,
      sourceIndex: 0,
    });

    expect(result).toEqual({
      elementID: 1,
      targetParentId: 100,
      afterElementID: 2,
    });
  });

  it('handles cross-container move', () => {
    const result = resolveReorderParams({
      activeId: 'element-5',
      overContainerParentId: 50,
      overIndex: 1,
      containerItems: ['element-10', 'element-5'],
      sourceContainerParentId: 30,
      sourceIndex: 0,
    });

    expect(result).toEqual({
      elementID: 5,
      targetParentId: 50,
      afterElementID: 10,
    });
  });
});
