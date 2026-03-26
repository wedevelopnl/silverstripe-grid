/** @type {import('@stryker-mutator/api/core').PartialStrykerOptions} */
export default {
  testRunner: 'vitest',
  appendPlugins: ['./stryker-react-ignorer.mjs'],
  ignorers: ['react'],
  mutate: [
    'client/src/**/*.{ts,tsx}',
    '!client/src/**/*.{test,spec}.*',
    '!client/src/**/*.d.ts',
    '!client/src/**/tests/**',
    '!client/src/api/endpoints.ts',
    '!client/src/api/errors.ts',
  ],
  ignorePatterns: ['public'],
  checkers: ['typescript'],
  tsconfigFile: 'tsconfig.json',
  reporters: ['progress', 'clear-text', 'json'],
  jsonReporter: {
    fileName: 'reports/mutation/mutation.json',
  },
  thresholds: {
    high: 85,
    low: 75,
    break: 77,
  },
  // Allow clean exit when no source files exist to mutate yet
  allowEmpty: true,
};
