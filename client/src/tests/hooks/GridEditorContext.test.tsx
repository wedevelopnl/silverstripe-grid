import { renderHook } from '@testing-library/react';

import { useGridEditorContext } from '@/hooks/GridEditorContext';
import { createGridEditorWrapper } from '../helpers/dndTestUtils';

describe('GridEditorContext', () => {
  it('throws when useGridEditorContext is used outside a GridEditorProvider', () => {
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const suppressJsdomErrors = (event: ErrorEvent) => event.preventDefault();
    window.addEventListener('error', suppressJsdomErrors);

    expect(() => {
      renderHook(() => useGridEditorContext());
    }).toThrow('useGridEditorContext must be used within a GridEditorProvider');

    window.removeEventListener('error', suppressJsdomErrors);
    consoleSpy.mockRestore();
  });

  it('provides pageId and zone through the provider', () => {
    const { result } = renderHook(() => useGridEditorContext(), {
      wrapper: createGridEditorWrapper(99, 'sidebar').wrapper,
    });

    expect(result.current.pageId).toBe(99);
    expect(result.current.zone).toBe('sidebar');
  });
});
