import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import GridEditorErrorBoundary from './GridEditorErrorBoundary';

vi.mock('@/utils/toast', () => ({
  showToast: vi.fn(),
}));

function ThrowingChild(): React.JSX.Element {
  throw new Error('Render failure');
}

describe('GridEditorErrorBoundary', () => {
  let consoleSpy: ReturnType<typeof vi.spyOn>;
  let stderrSpy: ReturnType<typeof vi.spyOn> | undefined;

  beforeEach(() => {
    // Suppress React 18 dev-mode error boundary logging through both
    // console.error and jsdom's stderr write path
    consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    if (typeof process.stderr?.write === 'function') {
      stderrSpy = vi.spyOn(process.stderr, 'write').mockImplementation(() => true);
    }
  });

  afterEach(() => {
    consoleSpy.mockRestore();
    stderrSpy?.mockRestore();
  });

  it('renders children when no error occurs', () => {
    render(
      <GridEditorErrorBoundary>
        <p>Grid content</p>
      </GridEditorErrorBoundary>,
    );

    expect(screen.getByText('Grid content')).toBeInTheDocument();
  });

  it('shows fallback message when child throws', () => {
    render(
      <GridEditorErrorBoundary>
        <ThrowingChild />
      </GridEditorErrorBoundary>,
    );

    expect(
      screen.getByText('The grid editor failed to render. Try reloading the page.'),
    ).toBeInTheDocument();
  });

  it('calls showToast with error message when child throws', async () => {
    const { showToast } = await import('@/utils/toast');

    render(
      <GridEditorErrorBoundary>
        <ThrowingChild />
      </GridEditorErrorBoundary>,
    );

    expect(showToast).toHaveBeenCalledWith(
      'The grid editor encountered an error and could not render.',
    );
  });

  it('calls console.error when child throws', () => {
    render(
      <GridEditorErrorBoundary>
        <ThrowingChild />
      </GridEditorErrorBoundary>,
    );

    // Our componentDidCatch calls console.error with a specific prefix
    expect(consoleSpy).toHaveBeenCalledWith(
      '[GridEditor] Render error:',
      expect.any(Error),
      expect.objectContaining({ componentStack: expect.any(String) }),
    );
  });

  it('fallback has grid-editor__error class', () => {
    render(
      <GridEditorErrorBoundary>
        <ThrowingChild />
      </GridEditorErrorBoundary>,
    );

    const fallback = screen.getByText('The grid editor failed to render. Try reloading the page.');
    expect(fallback).toHaveClass('grid-editor__error');
  });
});
