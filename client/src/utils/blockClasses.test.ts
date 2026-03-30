import { describe, it, expect } from 'vitest';
import { buildBlockClasses } from './blockClasses';

describe('buildBlockClasses', () => {
  it('combines block name and status modifier', () => {
    expect(buildBlockClasses('card', 'published', {})).toBe('card card--published');
  });

  it('appends active modifiers', () => {
    expect(
      buildBlockClasses('card', 'draft', { active: true, highlighted: true }),
    ).toBe('card card--draft card--active card--highlighted');
  });

  it('skips inactive modifiers', () => {
    expect(
      buildBlockClasses('card', 'modified', { active: false, dragging: true }),
    ).toBe('card card--modified card--dragging');
  });

  it('works with no modifiers', () => {
    expect(buildBlockClasses('block', 'removed', {})).toBe('block block--removed');
  });
});
