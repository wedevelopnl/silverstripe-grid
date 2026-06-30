/** @type {import('@stryker-mutator/api/core').PartialStrykerOptions} */
export default {
  testRunner: 'vitest',
  appendPlugins: ['./stryker-ignorers.mjs'],
  ignorers: ['react', 'i18nKey', 'mutationResidue'],
  mutate: [
    'client/src/js/**/*.{ts,tsx}',
    '!client/src/js/**/*.{test,spec}.*',
    '!client/src/js/**/*.d.ts',
    '!client/src/js/**/tests/**',
    '!client/src/js/testing/**',
    '!client/src/js/**/index.ts',
    '!client/src/js/bundles/**',
    '!client/src/js/bridge/**',
    '!client/src/js/boot/**',
    '!client/src/js/types/adapter.ts',
    '!client/src/js/types/duplicateTo.ts',
    '!client/src/js/types/gridSettings.ts',
    '!client/src/js/types/silverstripe.d.ts',
    '!client/src/js/api/errors.ts',
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
    break: 75,
  },
  // Allow clean exit when no source files exist to mutate yet
  allowEmpty: true,
};
