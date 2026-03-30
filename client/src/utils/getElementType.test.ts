import { describe, it, expect } from 'vitest';
import { getElementType } from './getElementType';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories';

beforeEach(() => {
  resetIdCounter();
});

describe('getElementType', () => {
  it('returns containerType for container nodes', () => {
    expect(getElementType(createSectionNode())).toBe('section');
    expect(getElementType(createRowNode())).toBe('row');
    expect(getElementType(createColumnNode())).toBe('column');
  });

  it('returns "element" for simple element nodes', () => {
    expect(getElementType(createSimpleElement())).toBe('element');
  });
});
