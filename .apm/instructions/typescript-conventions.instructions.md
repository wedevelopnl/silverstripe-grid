---
description: TypeScript and React frontend conventions
applyTo: "**/*.{ts,tsx}"
---

# TypeScript & React Conventions

## Stack

- React 18, TypeScript 5.9, Vite 7, SCSS
- dnd-kit for drag & drop
- TanStack Query for data fetching
- Zod for runtime validation and schema definitions

## Structure

- `client/src/` is the frontend root
- `@` path alias maps to `client/src` (configured in `vite.config.ts` and `tsconfig.json`)
- Entry points in `client/src/bundles/`
- SilverStripe CMS integration via entwine and Injector in `client/src/bridge/`

## Testing

- Vitest + React Testing Library with jsdom environment
- Test files in `client/src/tests/`
- Stryker for mutation testing

## Key Patterns

- **Zod-first types**: Schemas defined first in `client/src/types/`, TS types inferred via `z.infer<>`. Discriminated unions for element nodes. Type guards for narrowing.
- **Query key factory**: `client/src/hooks/queryKeys.ts` provides factories for TanStack Query cache keys. Required for correct cache invalidation across mutations.
- **API client layers**: 4-file architecture in `client/src/api/` — `client.ts` (HTTP primitives), `endpoints.ts` (business operations), `config.ts` (CMS globals like security token, base URL), `errors.ts` (typed error classes).
- **Bridge pattern**: entwine in `client/src/bridge/` mounts React components into jQuery DOM. Injector wraps SilverStripe DI. New components registered via `client/src/boot/registerComponents.ts`.

## Drag & Drop (dnd-kit)

See the `dnd-guide` skill for the full reference — coordinate spaces, collision detection, pending tree, diagnostics, and system invariants. The skill is triggered automatically when working on DnD files.

### GridSettings (Sparse Storage)

Column grid settings use mobile-first cascade. Only viewport overrides are stored — defaults (`width=12, offset=0, visible=true`) cascade from the smallest viewport. `Column::getColumnClasses()` walks viewports smallest→largest, emitting CSS classes only when the effective value changes from the previous breakpoint.
