import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import DuplicateToDialog from '@/components/DuplicateToDialog/DuplicateToDialog';
import type { PageEntry, AcceptableContainer } from '@/api/endpoints';
import { createQueryWrapper } from '../../helpers/dndTestUtils';

// jsdom does not implement HTMLDialogElement.showModal / close
const showModalSpy = vi.fn(function (this: HTMLDialogElement) {
  this.setAttribute('open', '');
});
const closeSpy = vi.fn(function (this: HTMLDialogElement) {
  this.removeAttribute('open');
});

beforeAll(() => {
  HTMLDialogElement.prototype.showModal = showModalSpy;
  HTMLDialogElement.prototype.close = closeSpy;
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

const { mockUsePages, mockUseZones, mockUseAcceptableContainers } = vi.hoisted(() => ({
  mockUsePages: vi.fn(),
  mockUseZones: vi.fn(),
  mockUseAcceptableContainers: vi.fn(),
}));

vi.mock('@/hooks/useDuplicateToQueries', () => ({
  usePages: mockUsePages,
  useZones: mockUseZones,
  useAcceptableContainers: mockUseAcceptableContainers,
}));

const defaultProps = {
  isOpen: true,
  elementType: 'row',
  currentPageId: 1,
  onConfirm: vi.fn(),
  onCancel: vi.fn(),
};

/** Navigate from page step to zone step. */
async function advanceToZone(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByTestId('duplicate-to-next'));
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-step-zone')).toBeDefined();
  });
}

/** Navigate from page step through zone step to container step. */
async function advanceToContainer(user: ReturnType<typeof userEvent.setup>) {
  await advanceToZone(user);

  // Select a zone and advance
  await user.click(screen.getAllByTestId('duplicate-to-zone-item')[0]);
  await user.click(screen.getByTestId('duplicate-to-next'));
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-step-container')).toBeDefined();
  });
}

describe('DuplicateToDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockUsePages.mockReturnValue({ data: mockPages, isLoading: false });
    mockUseZones.mockReturnValue({ data: mockZones, isLoading: false });
    mockUseAcceptableContainers.mockReturnValue({ data: mockContainers, isLoading: false });
  });

  // --- Existing tests ---

  it('renders page list on open', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

    expect(screen.getByTestId('duplicate-to-step-page')).toBeDefined();
    expect(screen.getByTestId('duplicate-to-page-list')).toBeDefined();
    expect(screen.getAllByTestId('duplicate-to-page-item')).toHaveLength(3);
  });

  it('shows disabled pages with aria-disabled', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

    const items = screen.getAllByTestId('duplicate-to-page-item');
    const blogItem = items.find((item) => item.textContent === 'Blog');
    expect(blogItem?.getAttribute('aria-disabled')).toBe('true');
  });

  it('pre-selects the current page', () => {
    render(<DuplicateToDialog {...defaultProps} currentPageId={1} />, { wrapper: createQueryWrapper().wrapper });

    const items = screen.getAllByTestId('duplicate-to-page-item');
    const homeItem = items.find((item) => item.textContent === 'Home');
    expect(homeItem?.getAttribute('aria-selected')).toBe('true');
  });

  it('can navigate from page step to zone step', async () => {
    const user = userEvent.setup();

    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

    await user.click(screen.getByTestId('duplicate-to-next'));

    await waitFor(() => {
      expect(screen.getByTestId('duplicate-to-step-zone')).toBeDefined();
    });
  });

  it('shows zone list with multiple zones', async () => {
    const user = userEvent.setup();

    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

    await user.click(screen.getByTestId('duplicate-to-next'));

    await waitFor(() => {
      expect(screen.getByTestId('duplicate-to-zone-list')).toBeDefined();
      expect(screen.getAllByTestId('duplicate-to-zone-item')).toHaveLength(2);
    });
  });

  it('can navigate back from zone step to page step', async () => {
    const user = userEvent.setup();

    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

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
      { wrapper: createQueryWrapper().wrapper },
    );

    expect(screen.getByTestId('duplicate-to-error')).toBeDefined();
    expect(screen.getByText('Something went wrong')).toBeDefined();
  });

  it('does not display error when error prop is null', () => {
    render(
      <DuplicateToDialog {...defaultProps} error={null} />,
      { wrapper: createQueryWrapper().wrapper },
    );

    expect(screen.queryByTestId('duplicate-to-error')).toBeNull();
  });

  it('has duplicate-to-dialog test id', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

    expect(screen.getByTestId('duplicate-to-dialog')).toBeDefined();
  });

  it('renders search input on page step', () => {
    render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

    expect(screen.getByTestId('duplicate-to-search')).toBeDefined();
  });

  it('calls onCancel when cancel button is clicked', async () => {
    const onCancel = vi.fn();
    const user = userEvent.setup();

    render(
      <DuplicateToDialog {...defaultProps} onCancel={onCancel} />,
      { wrapper: createQueryWrapper().wrapper },
    );

    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onCancel).toHaveBeenCalledOnce();
  });

  // --- Page step ---

  describe('page step', () => {
    it('shows "Select target page" title', () => {
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      expect(screen.getByText('Select target page')).toBeDefined();
    });

    it('selecting a page updates aria-selected', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      const items = screen.getAllByTestId('duplicate-to-page-item');
      const aboutItem = items.find((item) => item.textContent === 'About')!;
      const homeItem = items.find((item) => item.textContent === 'Home')!;

      await user.click(aboutItem);

      expect(aboutItem.getAttribute('aria-selected')).toBe('true');
      expect(homeItem.getAttribute('aria-selected')).toBe('false');
    });

    it('clicking disabled page does not change selection', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      const items = screen.getAllByTestId('duplicate-to-page-item');
      const blogItem = items.find((item) => item.textContent === 'Blog')!;
      const homeItem = items.find((item) => item.textContent === 'Home')!;

      await user.click(blogItem);

      // Home should remain selected
      expect(homeItem.getAttribute('aria-selected')).toBe('true');
    });

    it('Next button is disabled when currentPageId is 0', () => {
      render(
        <DuplicateToDialog {...defaultProps} currentPageId={0} />,
        { wrapper: createQueryWrapper().wrapper },
      );

      expect(screen.getByTestId('duplicate-to-next')).toHaveProperty('disabled', true);
    });

    it('shows loading state for pages', () => {
      mockUsePages.mockReturnValue({ data: undefined, isLoading: true });

      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      expect(screen.getByText(/Loading pages/)).toBeDefined();
      expect(screen.queryByTestId('duplicate-to-page-list')).toBeNull();
    });

    it('updates search input value', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      const input = screen.getByTestId('duplicate-to-search');
      await user.type(input, 'test');

      expect(input).toHaveProperty('value', 'test');
    });
  });

  // --- Zone step ---

  describe('zone step', () => {
    it('shows "Select zone" title', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToZone(user);

      expect(screen.getByText('Select zone')).toBeDefined();
    });

    it('selecting a zone updates aria-selected', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToZone(user);

      const items = screen.getAllByTestId('duplicate-to-zone-item');
      await user.click(items[1]); // click 'sidebar'

      expect(items[1].getAttribute('aria-selected')).toBe('true');
    });

    it('Next button is disabled when no zone is selected', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToZone(user);

      expect(screen.getByTestId('duplicate-to-next')).toHaveProperty('disabled', true);
    });

    it('shows loading state for zones', async () => {
      const user = userEvent.setup();
      // Start with loaded pages, loading zones
      mockUseZones.mockReturnValue({ data: undefined, isLoading: true });

      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToZone(user);

      expect(screen.getByText(/Loading zones/)).toBeDefined();
    });

    it('auto-advances to container step with single zone (non-section)', async () => {
      const user = userEvent.setup();
      mockUseZones.mockReturnValue({ data: ['main'], isLoading: false });

      render(<DuplicateToDialog {...defaultProps} elementType="row" />, { wrapper: createQueryWrapper().wrapper });

      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-container')).toBeDefined();
      });
    });

    it('auto-advances to confirm step with single zone (section)', async () => {
      const user = userEvent.setup();
      mockUseZones.mockReturnValue({ data: ['main'], isLoading: false });

      render(<DuplicateToDialog {...defaultProps} elementType="section" />, { wrapper: createQueryWrapper().wrapper });

      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeDefined();
      });
    });
  });

  // --- Container step ---

  describe('container step', () => {
    it('shows "Select container" title', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToContainer(user);

      expect(screen.getByText('Select container')).toBeDefined();
    });

    it('renders container items with title and type', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToContainer(user);

      const items = screen.getAllByTestId('duplicate-to-container-item');
      expect(items).toHaveLength(2);
      expect(items[0].textContent).toContain('Row 1');
      expect(items[0].textContent).toContain('row');
    });

    it('selecting a container updates aria-selected', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToContainer(user);

      const items = screen.getAllByTestId('duplicate-to-container-item');
      await user.click(items[0]);

      expect(items[0].getAttribute('aria-selected')).toBe('true');
    });

    it('Confirm button is disabled when no container selected', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToContainer(user);

      expect(screen.getByTestId('duplicate-to-confirm')).toHaveProperty('disabled', true);
    });

    it('Confirm calls onConfirm with correct arguments', async () => {
      const onConfirm = vi.fn();
      const user = userEvent.setup();
      render(
        <DuplicateToDialog {...defaultProps} onConfirm={onConfirm} />,
        { wrapper: createQueryWrapper().wrapper },
      );

      await advanceToContainer(user);

      const items = screen.getAllByTestId('duplicate-to-container-item');
      await user.click(items[0]); // select Row 1 (id: 10)
      await user.click(screen.getByTestId('duplicate-to-confirm'));

      expect(onConfirm).toHaveBeenCalledWith(1, 'main', 10);
    });

    it('shows empty containers message', async () => {
      mockUseAcceptableContainers.mockReturnValue({ data: [], isLoading: false });

      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToContainer(user);

      expect(screen.getByTestId('duplicate-to-no-containers')).toBeDefined();
      expect(screen.getByText(/No compatible containers/)).toBeDefined();
    });

    it('shows loading state for containers', async () => {
      mockUseAcceptableContainers.mockReturnValue({ data: undefined, isLoading: true });

      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToContainer(user);

      expect(screen.getByText(/Loading containers/)).toBeDefined();
    });

    it('Back button navigates to zone step', async () => {
      const user = userEvent.setup();
      render(<DuplicateToDialog {...defaultProps} />, { wrapper: createQueryWrapper().wrapper });

      await advanceToContainer(user);
      await user.click(screen.getByTestId('duplicate-to-back'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeDefined();
      });
    });
  });

  // --- Confirm step (section flow) ---

  describe('confirm step (section flow)', () => {
    async function advanceToConfirm(user: ReturnType<typeof userEvent.setup>) {
      await advanceToZone(user);
      const items = screen.getAllByTestId('duplicate-to-zone-item');
      await user.click(items[0]); // select 'main'
      await user.click(screen.getByTestId('duplicate-to-next'));
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeDefined();
      });
    }

    it('shows "Confirm duplication" title', async () => {
      const user = userEvent.setup();
      render(
        <DuplicateToDialog {...defaultProps} elementType="section" />,
        { wrapper: createQueryWrapper().wrapper },
      );

      await advanceToConfirm(user);

      expect(screen.getByText('Confirm duplication')).toBeDefined();
    });

    it('shows zone name in summary', async () => {
      const user = userEvent.setup();
      render(
        <DuplicateToDialog {...defaultProps} elementType="section" />,
        { wrapper: createQueryWrapper().wrapper },
      );

      await advanceToConfirm(user);

      expect(screen.getByText(/main/)).toBeDefined();
    });

    it('Confirm calls onConfirm with pageId as parent', async () => {
      const onConfirm = vi.fn();
      const user = userEvent.setup();
      render(
        <DuplicateToDialog {...defaultProps} elementType="section" onConfirm={onConfirm} />,
        { wrapper: createQueryWrapper().wrapper },
      );

      await advanceToConfirm(user);
      await user.click(screen.getByTestId('duplicate-to-confirm'));

      // For sections, targetParentId === selectedPageId
      expect(onConfirm).toHaveBeenCalledWith(1, 'main', 1);
    });

    it('Back button navigates to zone step', async () => {
      const user = userEvent.setup();
      render(
        <DuplicateToDialog {...defaultProps} elementType="section" />,
        { wrapper: createQueryWrapper().wrapper },
      );

      await advanceToConfirm(user);
      await user.click(screen.getByTestId('duplicate-to-back'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeDefined();
      });
    });
  });

  // --- Dialog lifecycle ---

  describe('dialog lifecycle', () => {
    it('calls showModal when isOpen transitions to true', () => {
      const { rerender } = render(
        <DuplicateToDialog {...defaultProps} isOpen={false} />,
        { wrapper: createQueryWrapper().wrapper },
      );

      showModalSpy.mockClear();

      rerender(<DuplicateToDialog {...defaultProps} isOpen={true} />);

      expect(showModalSpy).toHaveBeenCalledOnce();
    });

    it('calls close when isOpen transitions to false', () => {
      const wrapper = createQueryWrapper().wrapper;
      const { rerender } = render(
        <DuplicateToDialog {...defaultProps} isOpen={true} />,
        { wrapper },
      );

      closeSpy.mockClear();

      rerender(<DuplicateToDialog {...defaultProps} isOpen={false} />);

      expect(closeSpy).toHaveBeenCalledOnce();
    });

    it('resets state when dialog reopens', async () => {
      const user = userEvent.setup();
      const wrapper = createQueryWrapper().wrapper;

      const { rerender } = render(
        <DuplicateToDialog {...defaultProps} isOpen={true} />,
        { wrapper },
      );

      // Navigate to zone step
      await user.click(screen.getByTestId('duplicate-to-next'));
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeDefined();
      });

      // Close and reopen
      rerender(<DuplicateToDialog {...defaultProps} isOpen={false} />);
      rerender(<DuplicateToDialog {...defaultProps} isOpen={true} />);

      // Should be back on page step
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeDefined();
      });
    });
  });
});
