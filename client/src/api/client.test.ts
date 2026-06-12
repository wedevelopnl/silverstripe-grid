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

    try {
      await apiGet('/api/broken')
    } catch (error) {
      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).status).toBe(500)
    }
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

  it('falls back to statusText when body is a string (non-object)', async () => {
    mockFetchError(400, undefined)
    // Override mock to return a string body
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 400,
      statusText: 'Bad Request',
      json: () => Promise.resolve('plain string'),
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

    await expect(apiGet('/api/test')).rejects.toThrow('Bad Request')
  })

  it('falls back to statusText when body is null', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
      json: () => Promise.resolve(null),
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

    await expect(apiGet('/api/test')).rejects.toThrow('Internal Server Error')
  })

  it('falls back to statusText when body is an array', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 422,
      statusText: 'Unprocessable Entity',
      json: () => Promise.resolve([1, 2, 3]),
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

    // Arrays pass typeof === 'object' but have no message/errorMessage fields
    await expect(apiGet('/api/test')).rejects.toThrow('Unprocessable Entity')
  })

  it('skips empty string message and falls through to errorMessage', async () => {
    mockFetchError(400, { message: '', errorMessage: 'Fallback' } as object)

    await expect(apiGet('/api/test')).rejects.toThrow('Fallback')
  })

  it('skips empty string errorMessage and falls through to statusText', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 400,
      statusText: 'Bad Request',
      json: () => Promise.resolve({ message: '', errorMessage: '' }),
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

    await expect(apiGet('/api/test')).rejects.toThrow('Bad Request')
  })

  it('falls back to statusText when JSON parsing fails', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 502,
      statusText: 'Bad Gateway',
      json: () => Promise.reject(new SyntaxError('Unexpected token')),
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

    await expect(apiGet('/api/test')).rejects.toThrow('Bad Gateway')
  })
})

describe('mutation request headers', () => {
  it('sends exact Content-Type application/json for POST', async () => {
    mockFetchSuccess({})

    await apiPost('/api/create', { name: 'test' })

    const [, init] = getFetchCalls()[0]
    const headers = init?.headers as Record<string, string>
    expect(headers['Content-Type']).toBe('application/json')
  })

  it('sends exact Content-Type application/json for PATCH', async () => {
    mockFetchSuccess({})

    await apiPatch('/api/update', { id: 1 })

    const [, init] = getFetchCalls()[0]
    const headers = init?.headers as Record<string, string>
    expect(headers['Content-Type']).toBe('application/json')
  })

  it('does not send Content-Type for DELETE (no body)', async () => {
    mockFetchSuccess({})

    await apiDelete('/api/remove', { id: 1 })

    const [, init] = getFetchCalls()[0]
    const headers = init?.headers as Record<string, string>
    expect(headers['Content-Type']).toBeUndefined()
  })
})
