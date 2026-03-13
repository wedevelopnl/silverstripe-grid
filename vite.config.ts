/// <reference types="vitest/config" />
import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import dts from 'vite-plugin-dts';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [
    react(),
    dts({
      include: ['client/src/types/**/*.ts'],
      exclude: ['client/src/types/silverstripe.d.ts'],
      outDir: 'client/dist',
      // Resolve @/* path aliases to relative imports in .d.ts output
      tsconfigPath: './tsconfig.json',
    }),
  ],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'client/src'),
    },
  },
  // Vite's lib mode doesn't replace process.env.NODE_ENV automatically
  // (unlike app mode). The JSX runtime checks this to choose between
  // production and development bundles — without it, the literal
  // `process.env.NODE_ENV` appears in the output and crashes in browsers.
  // Scoped to build only: Vitest needs the dev build for act() support.
  define: process.env.VITEST
    ? undefined
    : { 'process.env.NODE_ENV': JSON.stringify('production') },
  build: {
    outDir: 'client/dist',
    // SilverStripe's vendor-plugin creates symlinks in public/_resources/
    // pointing back to client/dist — copying that would cause infinite recursion.
    copyPublicDir: false,
    lib: {
      entry: resolve(__dirname, 'client/src/bundles/bundle.ts'),
      name: 'Grid',
      formats: ['iife'],
      fileName: () => 'js/bundle.js',
    },
    rollupOptions: {
      external: ['react', 'react-dom', 'react-dom/client'],
      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDom',
          'react-dom/client': 'ReactDomClient',
        },
        assetFileNames: (assetInfo) => {
          if (assetInfo.names.some((name) => name.endsWith('.css'))) {
            return 'styles/bundle.css';
          }
          return 'assets/[name][extname]';
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['client/src/**/*.{test,spec}.{ts,tsx}'],
    css: true,
    coverage: {
      thresholds: {
        statements: 90,
        branches: 84,
        functions: 92,
        lines: 92,
      },
    },
  },
});
