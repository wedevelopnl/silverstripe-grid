import { describe, expect, it, vi } from 'vitest'
import { getFetchCalls, mockFetchError, mockFetchSuccess } from '@/testing/mockFetch'
import { apiDelete, apiGet, apiPatch, apiPost } from './client'
import { ApiError } from './errors'

describe('apiGet', () => {
  it('sends GET with correct headers', async () => {
    mockFetchSuccess({ data: 'test' })

    await apiGet('/api/test')

    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/api/test')
    expect(init?.method).toBeUndefined() // GET is default
    expect(init?.credentials).toBe('same-origin')
    expect(init?.headers).toEqual({ Accept: 'application/json' })
  })

  it('returns parsed JSON body', async () => {
    mockFetchSuccess({ items: [1, 2, 3] })

    const result = await apiGet<{ items: number[] }>('/api/test')
    expect(result).toEqual({ items: [1, 2, 3] })
  })

  it('throws ApiError on non-OK status with message from body', async () => {
    mockFetchError(404, { message: 'Not found' })

    await expect(apiGet('/api/missing')).rejects.toThrow(ApiError)
    await expect(apiGet('/api/missing')).rejects.toThrow('Not found')
  })

  it('falls back to statusText when body has no message', async () => {
    mockFetchError(500, {})

    const error = await apiGet('/api/broken').catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(500)
  })
})

describe('apiPost', () => {
  it('sends POST with JSON body and CSRF header', async () => {
    mockFetchSuccess({})

    await apiPost('/api/create', { name: 'test' })

    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/api/create')
    expect(init?.method).toBe('POST')
    expect(init?.body).toBe(JSON.stringify({ name: 'test' }))
    const postHeaders = init?.headers as Record<string, string>
    expect(postHeaders['X-SecurityID']).toBe('test-security-id')
    expect(postHeaders['Content-Type']).toBe('application/json')
  })

  it('throws ApiError on non-OK status', async () => {
    mockFetchError(422, { message: 'Validation failed' })

    await expect(apiPost('/api/create', {})).rejects.toThrow('Validation failed')
  })
})

describe('apiPatch', () => {
  it('sends PATCH with JSON body and CSRF header', async () => {
    mockFetchSuccess({})

    await apiPatch('/api/update', { id: 1 })

    const [, init] = getFetchCalls()[0]
    expect(init?.method).toBe('PATCH')
    const patchHeaders = init?.headers as Record<string, string>
    expect(patchHeaders['X-SecurityID']).toBe('test-security-id')
  })
})

describe('apiDelete', () => {
  it('sends DELETE with CSRF header and no request body', async () => {
    mockFetchSuccess({})

    await apiDelete('/api/remove', { id: 1 })

    const [, init] = getFetchCalls()[0]
    expect(init?.method).toBe('DELETE')
    expect(init?.body).toBeUndefined()
    const deleteHeaders = init?.headers as Record<string, string>
    expect(deleteHeaders['X-SecurityID']).toBe('test-security-id')
    expect(deleteHeaders.Accept).toBe('application/json')
  })

  it('omits the query string when params are present but all values are skipped', async () => {
    mockFetchSuccess({})

    await apiDelete('/api/remove', { viewport: null, other: undefined })

    const [url] = getFetchCalls()[0]
    expect(String(url)).toBe('/api/remove')
  })

  it('serializes params as a query string appended to the URL', async () => {
    mockFetchSuccess({})

    await apiDelete('/api/remove', { id: 5, zone: 'main' })

    const [url, init] = getFetchCalls()[0]
    const urlString = String(url)
    expect(urlString).toContain('/api/remove?')
    expect(urlString).toContain('id=5')
    expect(urlString).toContain('zone=main')
    expect(init?.body).toBeUndefined()
  })

  it('omits the query string when no params are provided', async () => {
    mockFetchSuccess({})

    await apiDelete('/api/remove')

    const [url, init] = getFetchCalls()[0]
    expect(String(url)).toBe('/api/remove')
    expect(init?.body).toBeUndefined()
  })

  it('skips null and undefined param values', async () => {
    mockFetchSuccess({})

    await apiDelete('/api/remove', { id: 5, viewport: null, other: undefined })

    const [url] = getFetchCalls()[0]
    const urlString = String(url)
    expect(urlString).toContain('id=5')
    expect(urlString).not.toContain('viewport')
    expect(urlString).not.toContain('other')
  })
})

describe('error extraction', () => {
  it('extracts errorMessage field from response', async () => {
    mockFetchError(400, { errorMessage: 'Bad request body' })

    await expect(apiGet('/api/test')).rejects.toThrow('Bad request body')
  })

  it('prefers message over errorMessage', async () => {
    mockFetchError(400, { message: 'Primary', errorMessage: 'Secondary' })

    await expect(apiGet('/api/test')).rejects.toThrow('Primary')
  })

  it('extracts value from the SilverStripe AdminController errors envelope', async () => {
    // The exact body shape GridController emits via jsonError. Regression: the
    // client only read top-level message/errorMessage, so every server-composed
    // validation message was dropped and toasts showed only "API error 4xx:".
    mockFetchError(422, {
      status: 'error',
      errors: [{ type: 'error', code: 422, value: 'Element is not allowed in this container' }],
    })

    await expect(apiGet('/api/test')).rejects.toThrow('Element is not allowed in this container')
  })

  it('prefers the errors envelope value over a top-level message', async () => {
    mockFetchError(422, {
      message: 'flat',
      errors: [{ value: 'from envelope' }],
    })

    await expect(apiGet('/api/test')).rejects.toThrow('from envelope')
  })

  it('exposes the server message verbatim, without a technical prefix', async () => {
    // ApiError.message feeds toasts and the duplicate-to dialog directly — a
    // "API error 422:" prefix is developer noise in an editor-facing message.
    // The status stays available as a property for programmatic use.
    mockFetchError(422, {
      status: 'error',
      errors: [{ type: 'error', code: 422, value: 'Element is not allowed in this container' }],
    })

    const error = await apiGet('/api/test').catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).message).toBe('Element is not allowed in this container')
    expect((error as ApiError).status).toBe(422)
  })

  it('joins every non-empty envelope error value instead of dropping all but the first', async () => {
    mockFetchError(422, {
      status: 'error',
      errors: [
        { type: 'error', code: 422, value: 'First problem' },
        { type: 'error', code: 422, value: '' },
        { type: 'error', code: 422, value: 'Second problem' },
      ],
    })

    const error = await apiGet('/api/test').catch((e: unknown) => e)
    expect((error as ApiError).message).toBe('First problem; Second problem')
  })

  it('falls back to a generic message when even statusText is empty', async () => {
    // fetch() responses over HTTP/2 often carry an empty statusText — the
    // toast must never end up blank.
    mockFetchWithBody(500, '', () => Promise.resolve(null))

    const error = await apiGet('/api/test').catch((e: unknown) => e)
    expect((error as ApiError).message).toBe('Request failed with status 500')
  })

  /**
   * Builds a full fetch Response mock whose `json()` resolves the supplied body
   * (or rejects, for the JSON-parse-failure case). Only status/statusText/json
   * vary between the statusText-fallback scenarios below; everything else is the
   * inert remainder of the Response interface.
   */
  function mockFetchWithBody(
    status: number,
    statusText: string,
    json: () => Promise<unknown>,
  ): void {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status,
      statusText,
      json,
      headers: new Headers(),
      redirected: false,
      type: 'basic',
      url: '',
      clone: vi.fn(),
      body: null,
      bodyUsed: false,
      arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
      blob: () => Promise.resolve(new Blob()),
      bytes: () => Promise.resolve(new Uint8Array()),
      formData: () => Promise.resolve(new FormData()),
      text: () => Promise.resolve(''),
    })
  }

  it.each([
    {
      name: 'body is a string (non-object)',
      status: 400,
      statusText: 'Bad Request',
      json: () => Promise.resolve('plain string'),
    },
    {
      name: 'body is null',
      status: 500,
      statusText: 'Internal Server Error',
      json: () => Promise.resolve(null),
    },
    {
      // Arrays pass typeof === 'object' but have no message/errorMessage fields.
      name: 'body is an array',
      status: 422,
      statusText: 'Unprocessable Entity',
      json: () => Promise.resolve([1, 2, 3]),
    },
    {
      name: 'message and errorMessage are both empty strings',
      status: 400,
      statusText: 'Bad Request',
      json: () => Promise.resolve({ message: '', errorMessage: '' }),
    },
    {
      name: 'errors envelope is empty',
      status: 422,
      statusText: 'Unprocessable Entity',
      json: () => Promise.resolve({ status: 'error', errors: [] }),
    },
    {
      name: 'errors envelope entries have empty values',
      status: 422,
      statusText: 'Unprocessable Entity',
      json: () => Promise.resolve({ status: 'error', errors: [{ value: '' }] }),
    },
    {
      name: 'JSON parsing fails',
      status: 502,
      statusText: 'Bad Gateway',
      json: () => Promise.reject(new SyntaxError('Unexpected token')),
    },
  ])('falls back to statusText when $name', async ({ status, statusText, json }) => {
    mockFetchWithBody(status, statusText, json)

    await expect(apiGet('/api/test')).rejects.toThrow(statusText)
  })

  it('skips empty string message and falls through to errorMessage', async () => {
    mockFetchError(400, { message: '', errorMessage: 'Fallback' } as object)

    await expect(apiGet('/api/test')).rejects.toThrow('Fallback')
  })
})

describe('mutation request headers', () => {
  it.each([
    {
      name: 'sends exact Content-Type application/json for POST',
      call: () => apiPost('/api/create', { name: 'test' }),
      expected: 'application/json',
    },
    {
      name: 'sends exact Content-Type application/json for PATCH',
      call: () => apiPatch('/api/update', { id: 1 }),
      expected: 'application/json',
    },
    {
      name: 'does not send Content-Type for DELETE (no body)',
      call: () => apiDelete('/api/remove', { id: 1 }),
      expected: undefined,
    },
  ])('$name', async ({ call, expected }) => {
    mockFetchSuccess({})

    await call()

    const [, init] = getFetchCalls()[0]
    const headers = init?.headers as Record<string, string>
    expect(headers['Content-Type']).toBe(expected)
  })

  it.each([
    {
      name: 'sends Accept application/json for POST',
      call: () => apiPost('/api/create', { name: 'test' }),
    },
    {
      name: 'sends Accept application/json for PATCH',
      call: () => apiPatch('/api/update', { id: 1 }),
    },
  ])('$name', async ({ call }) => {
    mockFetchSuccess({})

    await call()

    const [, init] = getFetchCalls()[0]
    const headers = init?.headers as Record<string, string>
    expect(headers.Accept).toBe('application/json')
  })
})
