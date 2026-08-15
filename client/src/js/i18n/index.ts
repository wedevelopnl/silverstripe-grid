type Params = Record<string, string | number>

/**
 * {placeholder} substitution, using the same regex as `window.ss.i18n.inject`
 * (see vendor/silverstripe/admin/client/dist/js/i18n.js).
 *
 * We always substitute here rather than delegating to the vendor helper,
 * because its replacer is `map[key] ? map[key] : match` — a falsy value leaves
 * the placeholder in the output verbatim. `{count: 0}` therefore rendered a
 * literal "{count} offset" in the column offset picker. Coercing through
 * String() also keeps a numeric 0 intact.
 */
const injectParams = (str: string, params: Params): string =>
  Object.entries(params).reduce((s, [k, v]) => s.replaceAll(`{${k}}`, String(v)), str)

/**
 * Translate a key via SilverStripe's `window.ss.i18n`.
 *
 * `_t(key, fallback)` is a pure dictionary lookup — it does NOT perform
 * placeholder substitution despite accepting extra args. Substitution is a
 * separate `inject(str, params)` call. Callers use a single `t()` helper and
 * we do both steps internally.
 */
export const t = (key: string, fallback: string, params?: Params): string => {
  if (typeof window === 'undefined' || !window.ss?.i18n) {
    return params ? injectParams(fallback, params) : fallback
  }
  const translated = window.ss.i18n._t(key, fallback)
  return params ? injectParams(translated, params) : translated
}
