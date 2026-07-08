/**
 * Thrown when an API request returns a non-OK HTTP status.
 *
 * `message` is the server's text verbatim: it feeds toasts and inline dialog
 * errors directly, so a technical "API error 422:" prefix would be developer
 * noise in an editor-facing message. The status is a property for
 * programmatic use.
 */
export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

/**
 * Thrown when required CMS globals (window.ss.config) are missing,
 * indicating the admin bundle has not loaded.
 */
export class ConfigError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ConfigError'
  }
}
