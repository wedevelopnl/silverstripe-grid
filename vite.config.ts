/// <reference types="vitest/config" />

import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import react from '@vitejs/plugin-react'
import { defineConfig, esmExternalRequirePlugin } from 'vite'
import dts from 'vite-plugin-dts'

const __dirname = dirname(fileURLToPath(import.meta.url))

export default defineConfig({
  plugins: [
    react(),
    dts({
      include: ['client/src/js/types/**/*.ts'],
      exclude: ['client/src/js/types/silverstripe.d.ts'],
      outDirs: 'client/dist',
      // Resolve @/* path aliases to relative imports in .d.ts output
      tsconfigPath: './tsconfig.json',
      // TypeScript 6 changed rootDir inference — pin it so .d.ts files
      // emit to client/dist/types/ instead of client/dist/client/src/js/types/
      compilerOptions: { rootDir: resolve(__dirname, 'client/src/js') },
    }),
  ],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'client/src/js'),
    },
  },
  // Vite's lib mode doesn't replace process.env.NODE_ENV automatically
  // (unlike app mode). The JSX runtime checks this to choose between
  // production and development bundles — without it, the literal
  // `process.env.NODE_ENV` appears in the output and crashes in browsers.
  // Scoped to build only: Vitest needs the dev build for act() support.
  define: process.env.VITEST ? undefined : { 'process.env.NODE_ENV': JSON.stringify('production') },
  build: {
    outDir: 'client/dist',
    // SilverStripe's vendor-plugin creates symlinks in public/_resources/
    // pointing back to client/dist — copying that would cause infinite recursion.
    copyPublicDir: false,
    lib: {
      entry: resolve(__dirname, 'client/src/js/bundles/bundle.ts'),
      name: 'Grid',
      formats: ['iife'],
      fileName: () => 'js/bundle.js',
    },
    rolldownOptions: {
      external: ['react-dom', 'react-dom/client'],
      plugins: [esmExternalRequirePlugin({ external: ['react'] })],
      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDom',
          'react-dom/client': 'ReactDomClient',
        },
        assetFileNames: (assetInfo) => {
          if (assetInfo.names.some((name) => name.endsWith('.css'))) {
            return 'styles/bundle.css'
          }
          return 'assets/[name][extname]'
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['client/src/js/**/*.{test,spec}.{ts,tsx}', 'scripts/**/*.test.mjs'],
    setupFiles: ['./vitest.setup.ts'],
    css: true,
    coverage: {
      include: ['client/src/js/**/*.{ts,tsx}'],
      exclude: [
        'client/src/js/bundles/**',
        'client/src/js/bridge/**',
        'client/src/js/boot/**',
        'client/src/js/testing/**',
        'client/src/js/types/silverstripe.d.ts',
        'client/src/js/types/adapter.ts',
        'client/src/js/types/duplicateTo.ts',
        'client/src/js/types/gridSettings.ts',
        'client/src/js/**/*.d.ts',
      ],
      thresholds: {
        statements: 90,
        branches: 85,
        functions: 90,
        lines: 90,
      },
    },
  },
})
