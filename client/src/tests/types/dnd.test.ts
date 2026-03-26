import {
  buildDraggableId,
  parseDraggableId,
  getDraggableType,
  getDraggableTypeForNode,
} from '@/types/dnd';
import { makeLeaf, makeColumn, makeRow, makeSection } from '../helpers/elementFactories';

describe('buildDraggableId', () => {
  it('builds a composite ID from type and numeric ID', () => {
    expect(buildDraggableId('section', 42)).toBe('section-42');
    expect(buildDraggableId('row', 17)).toBe('row-17');
    expect(buildDraggableId('column', 8)).toBe('column-8');
    expect(buildDraggableId('element', 103)).toBe('element-103');
  });
});

describe('parseDraggableId', () => {
  it('parses type and numeric ID from composite string', () => {
    expect(parseDraggableId('section-42')).toEqual({ type: 'section', id: 42 });
    expect(parseDraggableId('element-103')).toEqual({ type: 'element', id: 103 });
  });

  it('returns null for invalid format', () => {
    expect(parseDraggableId('invalid')).toBeNull();
    expect(parseDraggableId('')).toBeNull();
    expect(parseDraggableId('section-abc')).toBeNull();
    expect(parseDraggableId('unknown-42')).toBeNull();
  });

  it('returns null for non-positive IDs', () => {
    expect(parseDraggableId('section-0')).toBeNull();
    expect(parseDraggableId('section--1')).toBeNull();
  });

  it('returns null when separator is at the start', () => {
    expect(parseDraggableId('-42')).toBeNull();
  });
});

describe('getDraggableType', () => {
  it('returns the type portion of a draggable ID', () => {
    expect(getDraggableType('section-42')).toBe('section');
    expect(getDraggableType('row-17')).toBe('row');
  });

  it('returns null for invalid IDs', () => {
    expect(getDraggableType('invalid')).toBeNull();
  });
});

describe('getDraggableTypeForNode', () => {
  it('returns "section" for a section node', () => {
    expect(getDraggableTypeForNode(makeSection(1))).toBe('section');
  });

  it('returns "row" for a row node', () => {
    expect(getDraggableTypeForNode(makeRow(2))).toBe('row');
  });

  it('returns "column" for a column node', () => {
    expect(getDraggableTypeForNode(makeColumn(3))).toBe('column');
  });

  it('returns "element" for a leaf node', () => {
    expect(getDraggableTypeForNode(makeLeaf())).toBe('element');
  });
});
