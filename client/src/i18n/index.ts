type Params = Record<string, string | number>

/**
 * Local {placeholder} substitution — matches the regex used by
 * `window.ss.i18n.inject` (see vendor/silverstripe/admin/client/dist/js/i18n.js).
 * Used only in the fallback path when `ss.i18n` is not yet loaded.
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
  return params ? window.ss.i18n.inject(translated, params) : translated
}
