import { getSecurityId } from './config'
import { ApiError } from './errors'

/**
 * Try to extract a human-readable error message from a JSON response body.
 * Falls back to the HTTP status text if the body cannot be parsed.
 */
async function extractErrorMessage(response: Response): Promise<string> {
  try {
    const body: unknown = await response.json()
    if (typeof body === 'object' && body !== null) {
      const record = body as Record<string, unknown>
      if (typeof record.message === 'string' && record.message !== '') {
        return record.message
      }
      if (typeof record.errorMessage === 'string' && record.errorMessage !== '') {
        return record.errorMessage
      }
    }
  } catch {
    // Response has no JSON body — fall back to statusText
  }
  return response.statusText
}

/**
 * Perform a GET request to a CMS API endpoint.
 *
 * @throws ApiError on non-OK HTTP status
 */
export async function apiGet<T>(url: string): Promise<T> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    const message = await extractErrorMessage(response)
    throw new ApiError(response.status, message)
  }

  return response.json() as Promise<T>
}

/**
 * Shared mutation request logic: sends a JSON body with CSRF header.
 *
 * @throws ApiError on non-OK HTTP status
 */
async function apiMutate(method: 'POST' | 'PATCH', url: string, body: object): Promise<void> {
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-SecurityID': getSecurityId(),
    },
    body: JSON.stringify(body),
  })

  if (!response.ok) {
    const message = await extractErrorMessage(response)
    throw new ApiError(response.status, message)
  }
}

/**
 * Perform a POST request to a CMS API endpoint with JSON body.
 *
 * @throws ApiError on non-OK HTTP status
 */
export function apiPost(url: string, body: object): Promise<void> {
  return apiMutate('POST', url, body)
}

/**
 * Perform a PATCH request to a CMS API endpoint with JSON body.
 *
 * @throws ApiError on non-OK HTTP status
 */
export function apiPatch(url: string, body: object): Promise<void> {
  return apiMutate('PATCH', url, body)
}

/**
 * Serialize DELETE params onto the URL query string.
 *
 * DELETE requests do not carry a request body: parameters go into the query
 * string so intermediaries (proxies, CDNs, server frameworks) that drop or
 * ignore DELETE bodies still receive the params. Null/undefined values are
 * skipped so optional fields don't appear as the literal strings
 * "null"/"undefined".
 */
function buildDeleteQueryString(params: Record<string, unknown> | undefined): string {
  if (params === undefined) {
    return ''
  }

  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined) {
      continue
    }
    search.append(key, String(value))
  }

  const serialized = search.toString()
  return serialized === '' ? '' : `?${serialized}`
}

/**
 * Perform a DELETE request to a CMS API endpoint.
 *
 * Parameters are serialized as a query string rather than a JSON body: DELETE
 * bodies are not universally supported and SilverStripe's HTTPRequest exposes
 * query params via `$request->getVar()` regardless of the HTTP verb.
 *
 * @throws ApiError on non-OK HTTP status
 */
export async function apiDelete(url: string, params?: Record<string, unknown>): Promise<void> {
  const fullUrl = `${url}${buildDeleteQueryString(params)}`
  const response = await fetch(fullUrl, {
    method: 'DELETE',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-SecurityID': getSecurityId(),
    },
  })

  if (!response.ok) {
    const message = await extractErrorMessage(response)
    throw new ApiError(response.status, message)
  }
}
