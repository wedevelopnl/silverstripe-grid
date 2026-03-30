import { describe, it, expect, beforeEach } from 'vitest';
import { apiGet, apiPost, apiPatch, apiDelete } from './client';
import { ApiError } from './errors';
import { mockFetchSuccess, mockFetchError, getFetchCalls } from '@/testing/mockFetch';

describe('apiGet', () => {
  it('sends GET with correct headers', async () => {
    mockFetchSuccess({ data: 'test' });

    await apiGet('/api/test');

    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/api/test');
    expect(init?.method).toBeUndefined(); // GET is default
    expect(init?.credentials).toBe('same-origin');
    expect(init?.headers).toEqual({ Accept: 'application/json' });
  });

  it('returns parsed JSON body', async () => {
    mockFetchSuccess({ items: [1, 2, 3] });

    const result = await apiGet<{ items: number[] }>('/api/test');
    expect(result).toEqual({ items: [1, 2, 3] });
  });

  it('throws ApiError on non-OK status with message from body', async () => {
    mockFetchError(404, { message: 'Not found' });

    await expect(apiGet('/api/missing')).rejects.toThrow(ApiError);
    await expect(apiGet('/api/missing')).rejects.toThrow('Not found');
  });

  it('falls back to statusText when body has no message', async () => {
    mockFetchError(500, {});

    try {
      await apiGet('/api/broken');
    } catch (error) {
      expect(error).toBeInstanceOf(ApiError);
      expect((error as ApiError).status).toBe(500);
    }
  });
});

describe('apiPost', () => {
  it('sends POST with JSON body and CSRF header', async () => {
    mockFetchSuccess({});

    await apiPost('/api/create', { name: 'test' });

    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/api/create');
    expect(init?.method).toBe('POST');
    expect(init?.body).toBe(JSON.stringify({ name: 'test' }));
    expect((init?.headers as Record<string, string>)['X-SecurityID']).toBe('test-security-id');
    expect((init?.headers as Record<string, string>)['Content-Type']).toBe('application/json');
  });

  it('throws ApiError on non-OK status', async () => {
    mockFetchError(422, { message: 'Validation failed' });

    await expect(apiPost('/api/create', {})).rejects.toThrow('Validation failed');
  });
});

describe('apiPatch', () => {
  it('sends PATCH with JSON body and CSRF header', async () => {
    mockFetchSuccess({});

    await apiPatch('/api/update', { id: 1 });

    const [, init] = getFetchCalls()[0];
    expect(init?.method).toBe('PATCH');
    expect((init?.headers as Record<string, string>)['X-SecurityID']).toBe('test-security-id');
  });
});

describe('apiDelete', () => {
  it('sends DELETE with JSON body and CSRF header', async () => {
    mockFetchSuccess({});

    await apiDelete('/api/remove', { id: 1 });

    const [, init] = getFetchCalls()[0];
    expect(init?.method).toBe('DELETE');
    expect((init?.headers as Record<string, string>)['X-SecurityID']).toBe('test-security-id');
  });
});

describe('error extraction', () => {
  it('extracts errorMessage field from response', async () => {
    mockFetchError(400, { errorMessage: 'Bad request body' });

    await expect(apiGet('/api/test')).rejects.toThrow('Bad request body');
  });

  it('prefers message over errorMessage', async () => {
    mockFetchError(400, { message: 'Primary', errorMessage: 'Secondary' });

    await expect(apiGet('/api/test')).rejects.toThrow('Primary');
  });
});
