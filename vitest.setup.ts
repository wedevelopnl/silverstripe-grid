import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import type { AdapterConfig } from './client/src/types/adapter';
import type { SilverStripeConfig } from './client/src/types/silverstripe';

const CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\GridController';

const defaultAdapterConfig: AdapterConfig = {
  viewports: [
    { key: 'xs', label: 'Extra small' },
    { key: 'sm', label: 'Small' },
    { key: 'md', label: 'Medium' },
    { key: 'lg', label: 'Large' },
    { key: 'xl', label: 'Extra large' },
    { key: 'xxl', label: 'Extra extra large' },
  ],
  defaultViewport: 'md',
  columnCount: 12,
  rowClasses: 'row',
  offsetStrategy: 'margin',
  baseWidthClasses: Object.fromEntries(
    Array.from({ length: 12 }, (_, i) => [String(i + 1), `col-${i + 1}`]),
  ),
  baseOffsetClasses: Object.fromEntries(
    Array.from({ length: 12 }, (_, i) => [String(i), `offset-${i}`]),
  ),
};

const defaultConfig: SilverStripeConfig = {
  SecurityID: 'test-security-id',
  sections: [
    {
      name: CONTROLLER_FQCN,
      url: '/admin/grid',
      controllerLink: '/admin/grid/',
      gridAdapter: defaultAdapterConfig,
    },
  ],
};

// Stub CMS globals before each test file
beforeEach(() => {
  window.ss = {
    config: structuredClone(defaultConfig),
  };
});

afterEach(() => {
  vi.restoreAllMocks();

  // Reset fetch if it was mocked
  if (vi.isMockFunction(globalThis.fetch)) {
    vi.mocked(globalThis.fetch).mockRestore();
  }
});
