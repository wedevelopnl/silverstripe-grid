import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { NodeIdentity, type NodeKey } from '@/types/identity';

/**
 * Collapse state for a single grid editor instance.
 *
 * Exposes a narrow, stable API:
 * - `isCollapsed(nodeKey)` returns whether a given node is collapsed.
 * - `toggle(nodeKey)` flips the collapsed state and persists the change.
 *
 * The set of collapsed keys is stored by the backing hook and is not exposed
 * directly — callers always look up by NodeKey, never by raw set membership.
 */
export interface CollapseState {
  isCollapsed(nodeKey: NodeKey): boolean;
  toggle(nodeKey: NodeKey): void;
}

function buildStorageKey(areaId: number): string {
  return `grid:collapsed:${String(areaId)}`;
}

/**
 * Read the set of collapsed keys for a grid editor from localStorage.
 *
 * Returns an empty set for any malformed payload: missing/invalid JSON,
 * non-array, entries that don't parse as a valid NodeKey. Old numeric-ID
 * payloads from the pre-identity-refactor format are dropped silently —
 * we're in alpha, no migration contract.
 */
function readCollapsedKeys(areaId: number): ReadonlySet<NodeKey> {
  try {
    const raw = localStorage.getItem(buildStorageKey(areaId));
    if (raw === null) return new Set();

    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return new Set();

    const valid = parsed.filter(
      (value): value is NodeKey =>
        typeof value === 'string' && NodeIdentity.fromKey(value) !== null,
    );
    return new Set(valid);
  } catch {
    return new Set();
  }
}

function writeCollapsedKeys(areaId: number, keys: ReadonlySet<NodeKey>): void {
  try {
    localStorage.setItem(buildStorageKey(areaId), JSON.stringify([...keys]));
  } catch {
    // QuotaExceededError / SecurityError — silently ignore, matches the
    // behaviour of the old useTreeEnrichment persistence layer.
  }
}

/**
 * React hook: returns a stable {@link CollapseState} scoped to a single grid
 * editor instance (identified by `areaId` — typically the page ID). Persists
 * the set of collapsed keys to localStorage so it survives page reloads.
 */
export function useCollapseState(areaId: number): CollapseState {
  const [collapsedKeys, setCollapsedKeys] = useState<ReadonlySet<NodeKey>>(() =>
    readCollapsedKeys(areaId),
  );

  const isCollapsed = useCallback(
    (nodeKey: NodeKey): boolean => collapsedKeys.has(nodeKey),
    [collapsedKeys],
  );

  const toggle = useCallback(
    (nodeKey: NodeKey): void => {
      setCollapsedKeys((prev) => {
        const next = new Set(prev);
        if (next.has(nodeKey)) {
          next.delete(nodeKey);
        } else {
          next.add(nodeKey);
        }
        writeCollapsedKeys(areaId, next);
        return next;
      });
    },
    [areaId],
  );

  return useMemo<CollapseState>(() => ({ isCollapsed, toggle }), [isCollapsed, toggle]);
}

/**
 * React context for {@link CollapseState}. GridEditor wraps its tree in this
 * provider; block components read via {@link useCollapse} instead of threading
 * props down through every level.
 */
export const CollapseContext = createContext<CollapseState | null>(null);

/**
 * Consume the ambient {@link CollapseState} from a {@link CollapseContext}
 * provider. Throws a clear error if used outside a provider — beats a silent
 * null-deref deep inside a block component.
 */
export function useCollapse(): CollapseState {
  const ctx = useContext(CollapseContext);
  if (ctx === null) {
    throw new Error('useCollapse must be used within a <CollapseContext.Provider>');
  }
  return ctx;
}
