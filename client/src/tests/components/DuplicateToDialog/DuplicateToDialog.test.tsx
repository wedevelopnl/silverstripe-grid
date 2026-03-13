import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import DuplicateToDialog from '@/components/DuplicateToDialog/DuplicateToDialog';
import type { PageEntry, AcceptableContainer } from '@/api/endpoints';

// jsdom does not implement HTMLDialogElement.showModal / close
beforeAll(() => {
  HTMLDialogElement.prototype.showModal = vi.fn(function (this: HTMLDialogElement) {
    this.setAttribute('open', '');
  });
  HTMLDialogElement.prototype.close = vi.fn(function (this: HTMLDialogElement) {
    this.removeAttribute('open');
  });
});

const mockPages: PageEntry[] = [
  { id: 1, title: 'Home', parentId: 0, hasGridZones: true },
  { id: 2, title: 'About', parentId: 0, hasGridZones: true },
  { id: 3, title: 'Blog', parentId: 0, hasGridZones: false },
];

const mockZones = ['main', 'sidebar'];

const mockContainers: AcceptableContainer[] = [
  { id: 10, title: 'Row 1', type: 'row' },
  { id: 11, title: 'Column A', type: 'column' },
];

vi.mock('@/hooks/useDuplicateToQueries', () => ({
  usePages: vi.fn(() => ({
    data: mockPages,
    isLoading: false,
  })),
  useZones: vi.fn(() => ({
    data: mockZones,
    isLoading: false,
  })),
  useAcceptableContainers: vi.fn(() => ({
    data: mockContainers,
    isLoading: false,
  })),
}));

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        {children}
      </QueryClientProvider>
    );
  };
}

const defaultProps = {
  isOpen: true,
  elementType: 'row',
  currentPageId: 1,
  onConfirm: vi.fn(),
  onCancel: vi.fn(),
};

describe('DuplicateToDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders page list on open', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createWrapper() });

    expect(screen.getByTestId('duplicate-to-step-page')).toBeDefined();
    expect(screen.getByTestId('duplicate-to-page-list')).toBeDefined();
    expect(screen.getAllByTestId('duplicate-to-page-item')).toHaveLength(3);
  });

  it('shows disabled pages with aria-disabled', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createWrapper() });

    const items = screen.getAllByTestId('duplicate-to-page-item');
    const blogItem = items.find((item) => item.textContent === 'Blog');
    expect(blogItem?.getAttribute('aria-disabled')).toBe('true');
  });

  it('pre-selects the current page', () => {
    render(<DuplicateToDialog {...defaultProps} currentPageId={1} />, { wrapper: createWrapper() });

    const items = screen.getAllByTestId('duplicate-to-page-item');
    const homeItem = items.find((item) => item.textContent === 'Home');
    expect(homeItem?.getAttribute('aria-selected')).toBe('true');
  });

  it('can navigate from page step to zone step', async () => {
    const user = userEvent.setup();

    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createWrapper() });

    await user.click(screen.getByTestId('duplicate-to-next'));

    await waitFor(() => {
      expect(screen.getByTestId('duplicate-to-step-zone')).toBeDefined();
    });
  });

  it('shows zone list with multiple zones', async () => {
    const user = userEvent.setup();

    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createWrapper() });

    await user.click(screen.getByTestId('duplicate-to-next'));

    await waitFor(() => {
      expect(screen.getByTestId('duplicate-to-zone-list')).toBeDefined();
      expect(screen.getAllByTestId('duplicate-to-zone-item')).toHaveLength(2);
    });
  });

  it('can navigate back from zone step to page step', async () => {
    const user = userEvent.setup();

    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createWrapper() });

    await user.click(screen.getByTestId('duplicate-to-next'));

    await waitFor(() => {
      expect(screen.getByTestId('duplicate-to-step-zone')).toBeDefined();
    });

    await user.click(screen.getByTestId('duplicate-to-back'));

    await waitFor(() => {
      expect(screen.getByTestId('duplicate-to-step-page')).toBeDefined();
    });
  });

  it('displays error message when error prop is set', () => {
    render(
      <DuplicateToDialog {...defaultProps} error="Something went wrong" />,
      { wrapper: createWrapper() },
    );

    expect(screen.getByTestId('duplicate-to-error')).toBeDefined();
    expect(screen.getByText('Something went wrong')).toBeDefined();
  });

  it('does not display error when error prop is null', () => {
    render(
      <DuplicateToDialog {...defaultProps} error={null} />,
      { wrapper: createWrapper() },
    );

    expect(screen.queryByTestId('duplicate-to-error')).toBeNull();
  });

  it('has duplicate-to-dialog test id', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createWrapper() });

    expect(screen.getByTestId('duplicate-to-dialog')).toBeDefined();
  });

  it('renders search input on page step', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createWrapper() });

    expect(screen.getByTestId('duplicate-to-search')).toBeDefined();
  });

  it('calls onCancel when cancel button is clicked', async () => {
    const onCancel = vi.fn();
    const user = userEvent.setup();

    render(
      <DuplicateToDialog {...defaultProps} onCancel={onCancel} />,
      { wrapper: createWrapper() },
    );

    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onCancel).toHaveBeenCalledOnce();
  });
});
