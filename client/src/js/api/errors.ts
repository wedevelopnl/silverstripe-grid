/**
 * Thrown when an API request returns a non-OK HTTP status.
 */
export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(`API error ${status}: ${message}`)
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
