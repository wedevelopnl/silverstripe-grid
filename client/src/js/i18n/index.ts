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
 *
 * The replacement is a function, not a string: values are author-supplied
 * (element titles, server error messages) and `$$`, `$&`, `` $` `` and `$'` in
 * a string replacement are substitution patterns, not literal text — a block
 * titled "Q4 $$ report" would otherwise announce "Move Q4 $ report". A function
 * replacer is exempt from that expansion, as the vendor's own regex+function
 * form was.
 */
const injectParams = (str: string, params: Params): string =>
  Object.entries(params).reduce((s, [k, v]) => s.replaceAll(`{${k}}`, () => String(v)), str)

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
