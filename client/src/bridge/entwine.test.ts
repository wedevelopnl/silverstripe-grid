import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createElement } from 'react';

// Return a trivial component from the Injector so the real GridEditor (which
// pulls in TanStack Query, DnD, etc.) never loads during the test. We only
// care that the bridge reaches the mount path.
vi.mock('./Injector', () => ({
  loadComponent: vi.fn(() => () => createElement('div', { 'data-grid-editor-stub': 'true' })),
}));

const HOST_SELECTOR = '.grid-editor__container';

async function flushMicrotasks(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

function createHost(): HTMLElement {
  const host = document.createElement('div');
  host.className = 'grid-editor__container';
  host.setAttribute('data-schema', JSON.stringify({ 'grid-page-id': 1, 'grid-zone': 'main' }));
  return host;
}

describe('entwine bridge MutationObserver fallback', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div class="js-injector-boot"></div>';
    vi.resetModules();
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('mounts the grid editor when a host element is inserted after initial load (Pjax simulation)', async () => {
    await import('./entwine');

    const bootRoot = document.querySelector('.js-injector-boot');
    expect(bootRoot).not.toBeNull();

    const host = createHost();
    bootRoot?.appendChild(host);

    await flushMicrotasks();

    expect(host.getAttribute('data-grid-editor-mounted')).toBe('true');
    expect(host.querySelector('[data-grid-editor-stub="true"]')).not.toBeNull();
  });

  it('unmounts when the host element is removed from the DOM', async () => {
    await import('./entwine');

    const bootRoot = document.querySelector('.js-injector-boot');
    const host = createHost();
    bootRoot?.appendChild(host);
    await flushMicrotasks();

    expect(host.getAttribute('data-grid-editor-mounted')).toBe('true');

    host.remove();
    await flushMicrotasks();

    expect(document.querySelector(`${HOST_SELECTOR}[data-grid-editor-mounted="true"]`)).toBeNull();
  });
});
