import type { StatusFlags } from './elements';

export type ElementStatus = 'draft' | 'published' | 'modified' | 'removed';

export function getElementStatus(statusFlags: StatusFlags): ElementStatus {
  if (statusFlags.removedfromdraft !== undefined) {
    return 'removed';
  }
  if (statusFlags.addedtodraft !== undefined) {
    return 'draft';
  }
  if (statusFlags.modified !== undefined) {
    return 'modified';
  }
  return 'published';
}
