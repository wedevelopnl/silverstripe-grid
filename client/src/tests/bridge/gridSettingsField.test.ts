import { vi } from 'vitest';

interface EntwineRules {
  onchange?(this: unknown): void;
  [key: string]: unknown;
}

describe('gridSettingsField entwine bridge', () => {
  let capturedNamespace: string;
  let capturedSelector: string;
  let capturedRules: EntwineRules;
  let innerJQuery: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    vi.resetModules();

    const mockElement = {
      entwine: vi.fn((rules: EntwineRules) => {
        capturedRules = rules;
      }),
    };

    // The inner $ captured by the entwine callback closure — must return
    // mockElement during import so $(selector).entwine(rules) works
    innerJQuery = vi.fn((_selectorOrElement: unknown) => {
      capturedSelector = _selectorOrElement as string;
      return mockElement;
    });

    const mockJQuery = vi.fn() as unknown as typeof window.jQuery;

    mockJQuery.entwine = vi.fn(
      (namespace: string, callback: ($: typeof window.jQuery) => void) => {
        capturedNamespace = namespace;
        // Pass innerJQuery as the $ parameter to the entwine callback
        callback(innerJQuery as unknown as typeof window.jQuery);
      },
    );

    window.jQuery = mockJQuery;

    await import('@/bridge/gridSettingsField');
  });

  it('registers in the "ss" namespace', () => {
    expect(capturedNamespace).toBe('ss');
  });

  it('targets the override toggle selector', () => {
    expect(capturedSelector).toBe('.grid-settings-field .grid-settings-field__override-toggle');
  });

  it('onchange enables controls and marks row overridden when checked', () => {
    const propFn = vi.fn();
    const notFn = vi.fn(() => ({ prop: propFn }));
    const toggleClassFn = vi.fn();
    const findFn = vi.fn(() => ({ not: notFn }));
    const rowMock = { find: findFn, toggleClass: toggleClassFn };

    const thisElement = {};
    const wrappedThis = {
      closest: vi.fn(() => rowMock),
      is: vi.fn(() => true),
    };

    innerJQuery.mockReturnValue(wrappedThis);

    capturedRules.onchange!.call(thisElement);

    // $(this) was called with the context element
    expect(innerJQuery).toHaveBeenCalledWith(thisElement);
    expect(wrappedThis.closest).toHaveBeenCalledWith('tr');
    expect(wrappedThis.is).toHaveBeenCalledWith(':checked');
    expect(findFn).toHaveBeenCalledWith('select, input');
    expect(notFn).toHaveBeenCalledWith('.grid-settings-field__override-toggle');
    expect(propFn).toHaveBeenCalledWith('disabled', false);
    expect(toggleClassFn).toHaveBeenCalledWith('is-overridden', true);
  });

  it('onchange disables controls and removes overridden class when unchecked', () => {
    const propFn = vi.fn();
    const notFn = vi.fn(() => ({ prop: propFn }));
    const toggleClassFn = vi.fn();
    const findFn = vi.fn(() => ({ not: notFn }));
    const rowMock = { find: findFn, toggleClass: toggleClassFn };

    const thisElement = {};
    const wrappedThis = {
      closest: vi.fn(() => rowMock),
      is: vi.fn(() => false),
    };

    innerJQuery.mockReturnValue(wrappedThis);

    capturedRules.onchange!.call(thisElement);

    expect(propFn).toHaveBeenCalledWith('disabled', true);
    expect(toggleClassFn).toHaveBeenCalledWith('is-overridden', false);
  });

  it('onchange excludes the override toggle from being disabled', () => {
    const notFn = vi.fn(() => ({ prop: vi.fn() }));
    const findFn = vi.fn(() => ({ not: notFn }));
    const rowMock = { find: findFn, toggleClass: vi.fn() };

    const thisElement = {};
    innerJQuery.mockReturnValue({
      closest: vi.fn(() => rowMock),
      is: vi.fn(() => true),
    });

    capturedRules.onchange!.call(thisElement);

    expect(notFn).toHaveBeenCalledWith('.grid-settings-field__override-toggle');
  });
});
