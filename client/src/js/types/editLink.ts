import * as v from 'valibot'

/**
 * Guard for `editLink` values. CMS edit URLs ("/admin/pages/edit/show/5") are
 * root-relative and safe. Absolute URLs must use http/https; javascript:,
 * data:, vbscript:, and other schemes are rejected. Protocol-relative URLs
 * ("//evil.com") are also rejected — they start with "/" but also with "//",
 * so we require a single-slash prefix (!value.startsWith('//')).
 */
export function isSafeEditLink(value: string): boolean {
  // Relative links (CMS edit URLs like "/admin/pages/edit/show/5") are safe.
  // Absolute URLs must use http/https; reject javascript:, data:, vbscript:, etc.
  // Backslash rejection prevents browser backslash→slash normalisation open-redirect
  // (e.g. /\evil.com → https://evil.com/ per WHATWG URL spec).
  // Tab/LF/CR rejection prevents WHATWG-stripping open-redirect: browsers strip these
  // control characters from URLs before routing, so "/\t/evil.com" normalises to
  // "//evil.com" — a protocol-relative off-origin redirect.
  if (
    value.startsWith('/') &&
    !value.startsWith('//') &&
    !value.includes('\\') &&
    !/[\t\n\r]/.test(value)
  )
    return true
  try {
    const url = new URL(value)
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    // Not a parseable absolute URL and not root-relative → reject.
    return false
  }
}

/** A nullable CMS link that has passed {@link isSafeEditLink}. */
export const editLinkWireSchema = v.pipe(
  v.nullable(v.string()),
  v.check(
    (value) => value === null || isSafeEditLink(value),
    'editLink must be a relative path or an http(s) URL',
  ),
)
