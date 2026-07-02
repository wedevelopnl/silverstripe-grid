/**
 * Per-test allowlist for the fail-on-console gate.
 *
 * `vitest-fail-on-console` (configured in vitest.setup.ts) fails any test that
 * calls `console.error`/`console.warn`. Its `silenceMessage` hook consults
 * {@link isConsoleMessageAllowed} so individual tests can opt specific,
 * intentional output in via {@link allowConsole}. The allowlist is reset before
 * every test by the setup file.
 */

type ConsoleMatcher = string | RegExp

let allowed: ConsoleMatcher[] = []

/**
 * Whitelist expected `console.error`/`console.warn` output for the current test.
 * Matches a substring (string) or pattern (RegExp) against the message.
 */
export function allowConsole(...matchers: ConsoleMatcher[]): void {
  allowed.push(...matchers)
}

/** True if `message` matches something a test opted into via {@link allowConsole}. */
export function isConsoleMessageAllowed(message: string): boolean {
  return allowed.some((matcher) =>
    typeof matcher === 'string' ? message.includes(matcher) : matcher.test(message),
  )
}

/** Clear the allowlist. Called before each test by vitest.setup.ts. */
export function resetConsoleAllowlist(): void {
  allowed = []
}
