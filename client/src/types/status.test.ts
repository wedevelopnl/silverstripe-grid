import { describe, it, expect } from 'vitest';
import { getElementStatus } from './status';

describe('getElementStatus', () => {
  it('returns "removed" when removedfromdraft flag is set', () => {
    expect(
      getElementStatus({ removedfromdraft: { text: 'Removed', title: 'Removed from draft' } }),
    ).toBe('removed');
  });

  it('returns "draft" when addedtodraft flag is set', () => {
    expect(
      getElementStatus({ addedtodraft: { text: 'Draft', title: 'Added to draft' } }),
    ).toBe('draft');
  });

  it('returns "modified" when modified flag is set', () => {
    expect(
      getElementStatus({ modified: { text: 'Modified', title: 'Modified since publish' } }),
    ).toBe('modified');
  });

  it('returns "published" when no flags are set', () => {
    expect(getElementStatus({})).toBe('published');
  });

  it('prioritizes removedfromdraft over other flags', () => {
    expect(
      getElementStatus({
        removedfromdraft: { text: 'R', title: 'R' },
        addedtodraft: { text: 'D', title: 'D' },
        modified: { text: 'M', title: 'M' },
      }),
    ).toBe('removed');
  });

  it('prioritizes addedtodraft over modified', () => {
    expect(
      getElementStatus({
        addedtodraft: { text: 'D', title: 'D' },
        modified: { text: 'M', title: 'M' },
      }),
    ).toBe('draft');
  });
});
