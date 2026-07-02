/**
 * A plain `Map`-backed `Storage` used to give tests a working `localStorage`.
 *
 * jsdom implements Web Storage, but under vitest on Node its native experimental
 * `localStorage` global shadows jsdom's and is unavailable without
 * `--localstorage-file`, leaving `window.localStorage` undefined. `vitest.setup.ts`
 * installs this mock globally (cleared before each test) so every test has a
 * functional, isolated `localStorage`.
 */
export function createMockLocalStorage(): Storage {
  const store = new Map<string, string>()
  return {
    get length() {
      return store.size
    },
    clear: () => {
      store.clear()
    },
    getItem: (key: string): string | null => store.get(key) ?? null,
    setItem: (key: string, value: string): void => {
      store.set(key, value)
    },
    removeItem: (key: string): void => {
      store.delete(key)
    },
    key: (index: number): string | null => [...store.keys()][index] ?? null,
  }
}
