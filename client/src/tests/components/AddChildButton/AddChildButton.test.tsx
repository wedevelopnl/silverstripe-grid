import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import AddChildButton from '@/components/AddChildButton/AddChildButton';
import { GridEditorProvider } from '@/hooks/GridEditorContext';

const mockCreateElement = vi.fn();

vi.mock('@/api/endpoints', () => ({
  createElement: (...args: unknown[]) => mockCreateElement(...args),
}));

function createWrapper(pageId = 1, zone = 'main') {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  });

  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <GridEditorProvider value={{ pageId, zone }}>
          {children}
        </GridEditorProvider>
      </QueryClientProvider>
    );
  };
}

describe('AddChildButton', () => {
  afterEach(() => {
    mockCreateElement.mockReset();
  });

  it('renders empty-state variant with dynamic label', () => {
    render(
      <AddChildButton
        parentId={1}
        childType="row"
        childLabel="Row"
        variant="empty-state"
      />,
      { wrapper: createWrapper() },
    );

    expect(screen.getByTestId('add-child-empty')).toBeDefined();
    expect(screen.getByText('No rows yet')).toBeDefined();
    expect(screen.getByText('Add Row')).toBeDefined();
  });

  it('renders append variant with dynamic label', () => {
    render(
      <AddChildButton
        parentId={1}
        childType="column"
        childLabel="Column"
        variant="append"
      />,
      { wrapper: createWrapper() },
    );

    expect(screen.getByTestId('add-child-append')).toBeDefined();
    expect(screen.getByText('Add Column')).toBeDefined();
  });

  it('calls createElement with correct params on click', async () => {
    mockCreateElement.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(
      <AddChildButton
        parentId={5}
        childType="column"
        childLabel="Column"
        variant="append"
      />,
      { wrapper: createWrapper(10, 'main') },
    );

    await user.click(screen.getByTestId('add-child-button'));

    expect(mockCreateElement).toHaveBeenCalledWith(
      {
        containerType: 'column',
        parentId: 5,
      },
      expect.anything(),
    );
  });

  it('includes zone param when creating a section', async () => {
    mockCreateElement.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(
      <AddChildButton
        parentId={42}
        childType="section"
        childLabel="Section"
        variant="empty-state"
      />,
      { wrapper: createWrapper(42, 'sidebar') },
    );

    await user.click(screen.getByTestId('add-child-button'));

    expect(mockCreateElement).toHaveBeenCalledWith(
      {
        containerType: 'section',
        parentId: 42,
        zone: 'sidebar',
      },
      expect.anything(),
    );
  });

  it('disables button while mutation is pending', async () => {
    mockCreateElement.mockReturnValue(new Promise(() => {}));
    const user = userEvent.setup();

    render(
      <AddChildButton
        parentId={1}
        childType="row"
        childLabel="Row"
        variant="append"
      />,
      { wrapper: createWrapper() },
    );

    await user.click(screen.getByTestId('add-child-button'));

    expect(screen.getByTestId('add-child-button').getAttribute('disabled')).toBe('');
    expect(screen.getByText('Adding Row…')).toBeDefined();
  });
});
