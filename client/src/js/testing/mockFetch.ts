import { vi } from 'vitest'

interface MockResponse {
  status?: number
  body?: unknown
  statusText?: string
}

function createResponse({ status = 200, body = {}, statusText = 'OK' }: MockResponse): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText,
    json: () => Promise.resolve(body),
    headers: new Headers(),
    redirected: false,
    type: 'basic',
    url: '',
    clone: () => createResponse({ status, body, statusText }),
    body: null,
    bodyUsed: false,
    arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
    blob: () => Promise.resolve(new Blob()),
    bytes: () => Promise.resolve(new Uint8Array()),
    formData: () => Promise.resolve(new FormData()),
    text: () => Promise.resolve(JSON.stringify(body)),
  }
}

export function mockFetchSuccess(body: unknown, status = 200): void {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(createResponse({ status, body }))
}

export function mockFetchError(status: number, body?: object): void {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    createResponse({
      status,
      // Default to SilverStripe's AdminController error envelope — the exact shape
      // GridController emits via jsonError — so the mock contract matches the server.
      body: body ?? { status: 'error', errors: [{ type: 'error', code: status, value: `Error ${status}` }] },
      statusText: `Error ${status}`,
    }),
  )
}

export function mockFetchSequence(responses: MockResponse[]): void {
  const mock = vi.spyOn(globalThis, 'fetch')
  for (const response of responses) {
    mock.mockResolvedValueOnce(
      createResponse({ status: 200, body: {}, statusText: 'OK', ...response }),
    )
  }
}

export function getFetchCalls(): [input: string | URL | Request, init?: RequestInit][] {
  return vi.mocked(globalThis.fetch).mock.calls as [string | URL | Request, RequestInit?][]
}
