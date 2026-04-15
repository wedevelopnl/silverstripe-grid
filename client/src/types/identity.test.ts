import { describe, it, expect } from 'vitest';
import {
  buildNodeKey,
  parseNodeKey,
  nodeRefToKey,
  nodeRefEquals,
  assertNodeRef,
  type NodeRef,
  type NodeType,
} from './identity';

describe('buildNodeKey', () => {
  it.each<[NodeType, number, string]>([
    ['page', 1, 'page-1'],
    ['section', 42, 'section-42'],
    ['row', 17, 'row-17'],
    ['column', 99, 'column-99'],
    ['element', 7, 'element-7'],
  ])('builds "%s-%d" as "%s"', (type, id, expected) => {
    expect(buildNodeKey(type, id)).toBe(expected);
  });
});

describe('nodeRefToKey', () => {
  it('matches buildNodeKey for a NodeRef', () => {
    const ref: NodeRef = { type: 'section', id: 3 };
    expect(nodeRefToKey(ref)).toBe('section-3');
  });
});

describe('parseNodeKey', () => {
  it.each<[string, NodeRef]>([
    ['page-1', { type: 'page', id: 1 }],
    ['section-42', { type: 'section', id: 42 }],
    ['row-17', { type: 'row', id: 17 }],
    ['column-99', { type: 'column', id: 99 }],
    ['element-7', { type: 'element', id: 7 }],
  ])('parses "%s" → %o', (key, expected) => {
    expect(parseNodeKey(key)).toEqual(expected);
  });

  it('round-trips with buildNodeKey', () => {
    const ref: NodeRef = { type: 'page', id: 1 };
    expect(parseNodeKey(buildNodeKey(ref.type, ref.id))).toEqual(ref);
  });

  it.each<[string, string]>([
    ['empty string', ''],
    ['no separator', 'section'],
    ['unknown type', 'unknown-5'],
    ['non-numeric id', 'row-abc'],
    ['zero id', 'row-0'],
    ['negative id', 'row--1'],
    ['floating id', 'row-1.5'],
    ['leading separator', '-5'],
  ])('returns null for %s', (_label, key) => {
    expect(parseNodeKey(key)).toBeNull();
  });
});

describe('nodeRefEquals', () => {
  it('is true for identical refs', () => {
    expect(nodeRefEquals({ type: 'row', id: 1 }, { type: 'row', id: 1 })).toBe(true);
  });

  it('is false when type differs', () => {
    expect(nodeRefEquals({ type: 'page', id: 1 }, { type: 'section', id: 1 })).toBe(false);
  });

  it('is false when id differs', () => {
    expect(nodeRefEquals({ type: 'row', id: 1 }, { type: 'row', id: 2 })).toBe(false);
  });
});

describe('assertNodeRef', () => {
  it('returns a clean NodeRef for a valid shape', () => {
    const ref = assertNodeRef({ type: 'section', id: 5 }, 'test');
    expect(ref).toEqual({ type: 'section', id: 5 });
  });

  it.each<[string, unknown]>([
    ['null', null],
    ['string', 'section-1'],
    ['missing id', { type: 'section' }],
    ['missing type', { id: 1 }],
    ['invalid type', { type: 'unknown', id: 1 }],
    ['zero id', { type: 'section', id: 0 }],
    ['negative id', { type: 'section', id: -1 }],
    ['float id', { type: 'section', id: 1.5 }],
    ['string id', { type: 'section', id: '1' }],
  ])('throws for %s', (_label, value) => {
    expect(() => assertNodeRef(value, 'test')).toThrow(TypeError);
  });
});
