---
description: TypeScript and React frontend conventions
applyTo: "**/*.{ts,tsx}"
---

# TypeScript & React Conventions

## Stack

- React 18, TypeScript 6, Vite 8, plain modern CSS (no Sass)
- dnd-kit for drag & drop
- TanStack Query for data fetching
- Valibot for runtime validation and schema definitions

## Structure

- `client/src/js/` is the frontend root
- `@` path alias maps to `client/src/js` (configured in `vite.config.mts` and `tsconfig.json`)
- Entry points in `client/src/js/bundles/`
- SilverStripe CMS integration via entwine and Injector in `client/src/js/bridge/`

## Testing

- Vitest + React Testing Library with jsdom environment
- Test files co-located next to source (`.test.ts`/`.test.tsx`)
- Shared test infrastructure in `client/src/js/testing/` (factories, helpers, mocks)
- Stryker for mutation testing

## Key Patterns

- **Valibot-first types**: Schemas defined first in `client/src/js/types/`, TS types inferred via `v.InferOutput<>`. Discriminated unions for element nodes. Type guards for narrowing.
- **Query key factory**: `client/src/js/hooks/queryKeys.ts` provides factories for TanStack Query cache keys. Required for correct cache invalidation across mutations.
- **API client layers**: 4-file architecture in `client/src/js/api/` — `client.ts` (HTTP primitives), `endpoints.ts` (business operations), `config.ts` (CMS globals like security token, base URL), `errors.ts` (typed error classes).
- **Bridge pattern**: entwine in `client/src/js/bridge/` mounts React components into jQuery DOM. Injector wraps SilverStripe DI. New components registered via `client/src/js/boot/registerComponents.ts`.

## Non-Null Assertions (`!`)

- Biome's `style/noNonNullAssertion` rule is enabled in production code — avoid `!` in files under `client/src/js/` outside tests.
- **TanStack Query narrowing**: `enabled: x !== null` does **not** narrow `x` inside `queryFn`. Use `skipToken` from `@tanstack/react-query` instead: `queryFn: x !== null ? () => fetch(x) : skipToken`. See `client/src/js/hooks/useDuplicateToQueries.ts` for the canonical example.
- **Derived boolean flags** (`hasX = x !== null && ...`) are not type predicates and do not narrow later usages. Prefer introducing a local const with a nullish-coalesced fallback (`const children = column.children ?? []`) and use it directly.
- **Narrowing across closures**: TypeScript cannot carry narrowing into a callback body because the closed-over value could be reassigned between check and use. Capture the narrowed value to a local const before the callback: `const pointer = args.pointerCoordinates; /* use pointer inside callback */`.
- The rule is disabled for test files (`**/*.test.{ts,tsx}`) via `biome.json` `overrides`. Fixture walks like `result.current[0].children![0]` are idiomatic in tests — a wrong fixture fails the test loudly, which is the same signal a guard would produce.

## Drag & Drop (dnd-kit)

See the `dnd-guide` skill for the full reference — coordinate spaces, collision detection, pending tree, diagnostics, and system invariants. The skill is triggered automatically when working on DnD files.

### GridSettings (Default + Overrides)

Column grid settings use an intent-based model: `{ default: ViewportSettings, overrides: Record<string, ViewportSettings> }`. The `default` holds the base layout (width, offset, visible) applied to all viewports. The `overrides` map holds per-viewport deviations. `resolveViewportSettings` resolves the effective settings for a given viewport by checking for an override, falling back to the default.
