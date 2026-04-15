import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { renderWithProviders } from '@/testing/renderWithProviders';

import DuplicateToDialog from './DuplicateToDialog';

// jsdom doesn't support native dialog showModal/close — stub them
beforeEach(() => {
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '');
  });
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open');
  });
});

// --- Fake API data ---

const PAGES = [
  { id: 1, title: 'Home', parentId: 0, hasGridZones: true },
  { id: 2, title: 'About', parentId: 0, hasGridZones: true },
  { id: 3, title: 'Legacy', parentId: 0, hasGridZones: false },
];

const ZONES = ['main', 'sidebar'];

const CONTAINERS = [
  { id: 100, title: 'Row 1', type: 'Row' },
  { id: 101, title: 'Row 2', type: 'Row' },
];

/**
 * URL-based fetch mock that routes by API path.
 * Simulates the real API endpoints each query hook calls.
 */
function mockApiRoutes(overrides?: {
  pages?: typeof PAGES;
  zones?: string[];
  containers?: typeof CONTAINERS;
}) {
  const pages = overrides?.pages ?? PAGES;
  const zones = overrides?.zones ?? ZONES;
  const containers = overrides?.containers ?? CONTAINERS;

  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: string | URL | Request) => {
    const url =
      typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;

    let body: unknown = {};
    if (url.includes('/api/pages')) body = pages;
    else if (url.includes('/api/zones/')) body = zones;
    else if (url.includes('/api/acceptableContainers/')) body = containers;

    return {
      ok: true,
      status: 200,
      statusText: 'OK',
      json: () => Promise.resolve(body),
      headers: new Headers(),
      redirected: false,
      type: 'basic',
      url: '',
      clone() {
        return this;
      },
      body: null,
      bodyUsed: false,
      arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
      blob: () => Promise.resolve(new Blob()),
      bytes: () => Promise.resolve(new Uint8Array()),
      formData: () => Promise.resolve(new FormData()),
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response;
  });
}

function renderDialog(overrides: Partial<React.ComponentProps<typeof DuplicateToDialog>> = {}) {
  const props: React.ComponentProps<typeof DuplicateToDialog> = {
    isOpen: true,
    elementType: 'row',
    currentPageId: 1,
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    error: null,
    ...overrides,
  };

  return { ...renderWithProviders(<DuplicateToDialog {...props} />), props };
}

// --- Helpers to navigate through steps ---

async function goToPageStep() {
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-page-list')).toBeInTheDocument();
  });
}

async function selectPageAndAdvance(user: ReturnType<typeof userEvent.setup>, pageTitle = 'About') {
  await goToPageStep();
  await user.click(screen.getByText(pageTitle));
  await user.click(screen.getByTestId('duplicate-to-next'));
}

async function goToZoneStep(user: ReturnType<typeof userEvent.setup>) {
  await selectPageAndAdvance(user);
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument();
  });
}

async function selectZoneAndAdvance(user: ReturnType<typeof userEvent.setup>, zone = 'main') {
  await goToZoneStep(user);
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
  });
  await user.click(screen.getByText(zone));
  await user.click(screen.getByTestId('duplicate-to-next'));
}

async function goToContainerStep(user: ReturnType<typeof userEvent.setup>) {
  await selectZoneAndAdvance(user);
  await waitFor(() => {
    expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument();
  });
}

// --- Tests ---

describe('DuplicateToDialog', () => {
  describe('page step', () => {
    it('renders page list with pages from API', async () => {
      mockApiRoutes();
      renderDialog();

      await goToPageStep();

      expect(screen.getAllByTestId('duplicate-to-page-item')).toHaveLength(3);
      expect(screen.getByText('Home')).toBeInTheDocument();
      expect(screen.getByText('About')).toBeInTheDocument();
      expect(screen.getByText('Legacy')).toBeInTheDocument();
    });

    it('shows title "Select target page"', async () => {
      mockApiRoutes();
      renderDialog();

      expect(screen.getByText('Select target page')).toBeInTheDocument();
    });

    it('selects a page on click and highlights it', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToPageStep();
      await user.click(screen.getByText('About'));

      expect(screen.getByText('About').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      );
    });

    it('marks pages without grid zones as disabled', async () => {
      mockApiRoutes();
      renderDialog();

      await goToPageStep();

      const legacyItem = screen.getByText('Legacy').closest('[role="option"]');
      expect(legacyItem).toHaveAttribute('aria-disabled', 'true');
    });

    it('disabled pages cannot be selected', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToPageStep();

      // Select About first, then try to click Legacy (disabled)
      await user.click(screen.getByText('About'));
      await user.click(screen.getByText('Legacy'));

      // About should still be selected
      expect(screen.getByText('About').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      );
    });

    it('Next button advances to zone step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await selectPageAndAdvance(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument();
      });
      expect(screen.getByText('Select zone')).toBeInTheDocument();
    });

    it('does not show Back button on page step', async () => {
      mockApiRoutes();
      renderDialog();

      await goToPageStep();

      expect(screen.queryByTestId('duplicate-to-back')).not.toBeInTheDocument();
    });

    it('search debounces and updates page list', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToPageStep();

      const searchInput = screen.getByTestId('duplicate-to-search');
      await user.type(searchInput, 'About');

      // Wait for debounce to trigger new fetch
      await waitFor(() => {
        const calls = vi.mocked(globalThis.fetch).mock.calls;
        const pageSearchCalls = calls.filter(
          ([url]) =>
            typeof url === 'string' && url.includes('/api/pages') && url.includes('search='),
        );
        expect(pageSearchCalls.length).toBeGreaterThan(0);
      });
    });
  });

  describe('zone step', () => {
    it('renders zone list when multiple zones exist', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      expect(screen.getAllByTestId('duplicate-to-zone-item')).toHaveLength(2);
      expect(screen.getByText('main')).toBeInTheDocument();
      expect(screen.getByText('sidebar')).toBeInTheDocument();
    });

    it('selects zone on click and highlights it', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('sidebar'));

      expect(screen.getByText('sidebar').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      );
    });

    it('Next button is disabled until zone is selected', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToZoneStep(user);

      const nextBtn = screen.getByTestId('duplicate-to-next');
      expect(nextBtn).toBeDisabled();
    });

    it('auto-advances when only one zone exists', async () => {
      const user = userEvent.setup();
      mockApiRoutes({ zones: ['main'] });
      renderDialog();

      await selectPageAndAdvance(user);

      // Should skip zone step and go to container step directly
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument();
      });
    });

    it('auto-advances section type to confirm step when single zone', async () => {
      const user = userEvent.setup();
      mockApiRoutes({ zones: ['main'] });
      renderDialog({ elementType: 'section' });

      await selectPageAndAdvance(user);

      // Sections skip container step entirely
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument();
      });
    });

    it('Back button returns to page step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToZoneStep(user);

      await user.click(screen.getByTestId('duplicate-to-back'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeInTheDocument();
      });
    });

    it('Next button advances non-section to container step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument();
      });
    });

    it('Next button advances section to confirm step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'section' });

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument();
      });
    });
  });

  describe('container step', () => {
    it('renders container list from API', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      expect(screen.getAllByTestId('duplicate-to-container-item')).toHaveLength(2);
      expect(screen.getByText('Row 1')).toBeInTheDocument();
    });

    it('selects container on click', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('Row 1'));

      expect(screen.getByText('Row 1').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      );
    });

    it('Confirm button disabled until container selected', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-confirm')).toBeInTheDocument();
      });

      expect(screen.getByTestId('duplicate-to-confirm')).toBeDisabled();
    });

    it('shows empty state when no containers found', async () => {
      const user = userEvent.setup();
      mockApiRoutes({ containers: [] });
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-no-containers')).toBeInTheDocument();
      });

      expect(screen.getByText('No compatible containers found in this zone')).toBeInTheDocument();
    });

    it('Back button returns to zone step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await user.click(screen.getByTestId('duplicate-to-back'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument();
      });
    });

    it('Confirm calls onConfirm with selected page, zone, and container', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      const onConfirm = vi.fn();
      renderDialog({ elementType: 'row', onConfirm });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('Row 1'));
      await user.click(screen.getByTestId('duplicate-to-confirm'));

      // Row element → target parent type is 'section'.
      expect(onConfirm).toHaveBeenCalledWith(2, 'main', { type: 'section', id: 100 });
    });
  });

  describe('confirm step (section path)', () => {
    it('shows confirmation summary for section', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'section' });

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument();
      });

      expect(screen.getByText('Confirm duplication')).toBeInTheDocument();
    });

    it('Confirm calls onConfirm with page as parent for sections', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      const onConfirm = vi.fn();
      renderDialog({ elementType: 'section', onConfirm });

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-confirm')).toBeInTheDocument();
      });

      await user.click(screen.getByTestId('duplicate-to-confirm'));

      // Section: target parent is the page itself.
      expect(onConfirm).toHaveBeenCalledWith(2, 'main', { type: 'page', id: 2 });
    });

    it('Back button from confirm returns to page step when there is only one zone', async () => {
      const user = userEvent.setup();
      mockApiRoutes({ zones: ['main'] });
      renderDialog({ elementType: 'section' });

      // Navigate to confirm step (single-zone auto-advances from page -> confirm)
      await selectPageAndAdvance(user);
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument();
      });

      await user.click(screen.getByTestId('duplicate-to-back'));

      // Must land on page step — zone would immediately auto-advance again
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeInTheDocument();
      });
    });

    it('Back button from confirm returns to zone step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'section' });

      // Navigate to confirm step
      await goToZoneStep(user);
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });
      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument();
      });

      await user.click(screen.getByTestId('duplicate-to-back'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument();
      });
    });
  });

  describe('keyboard navigation', () => {
    it('selects page via Enter key', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToPageStep();

      const aboutItem = screen.getByText('About').closest('[role="option"]')!;
      await user.type(aboutItem, '{Enter}');

      expect(aboutItem).toHaveAttribute('aria-selected', 'true');
    });

    it('selects page via Space key', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToPageStep();

      const aboutItem = screen.getByText('About').closest('[role="option"]')!;
      await user.type(aboutItem, ' ');

      expect(aboutItem).toHaveAttribute('aria-selected', 'true');
    });

    it('selects zone via Enter key', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      const sidebarItem = screen.getByText('sidebar').closest('[role="option"]')!;
      await user.type(sidebarItem, '{Enter}');

      expect(sidebarItem).toHaveAttribute('aria-selected', 'true');
    });

    it('selects zone via Space key', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToZoneStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      const sidebarItem = screen.getByText('sidebar').closest('[role="option"]')!;
      await user.type(sidebarItem, ' ');

      expect(sidebarItem).toHaveAttribute('aria-selected', 'true');
    });

    it('selects container via Enter key', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      const row1Item = screen.getByText('Row 1').closest('[role="option"]')!;
      await user.type(row1Item, '{Enter}');

      expect(row1Item).toHaveAttribute('aria-selected', 'true');
    });

    it('selects container via Space key', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      const row1Item = screen.getByText('Row 1').closest('[role="option"]')!;
      await user.type(row1Item, ' ');

      expect(row1Item).toHaveAttribute('aria-selected', 'true');
    });
  });

  describe('loading states', () => {
    it('shows "Loading pages..." before page data arrives', () => {
      // Fetch that never resolves simulates loading
      vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}));
      renderDialog();

      expect(screen.getByText(/Loading pages/)).toBeInTheDocument();
      expect(screen.getByText(/Loading pages/)).toHaveClass('duplicate-to-dialog__loading');
    });

    it('shows "Loading zones..." before zone data arrives', async () => {
      const user = userEvent.setup();

      function createUrlResponse(body: unknown): Response {
        return {
          ok: true,
          status: 200,
          statusText: 'OK',
          json: () => Promise.resolve(body),
          headers: new Headers(),
          redirected: false,
          type: 'basic' as ResponseType,
          url: '',
          clone() {
            return this;
          },
          body: null,
          bodyUsed: false,
          arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
          blob: () => Promise.resolve(new Blob()),
          bytes: () => Promise.resolve(new Uint8Array()),
          formData: () => Promise.resolve(new FormData()),
          text: () => Promise.resolve(JSON.stringify(body)),
        } as Response;
      }

      vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: string | URL | Request) => {
        const url =
          typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;

        if (url.includes('/api/pages')) {
          return createUrlResponse(PAGES);
        }

        // Zones never resolve
        return new Promise(() => {});
      });

      renderDialog();

      await goToPageStep();
      await user.click(screen.getByText('About'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument();
      });

      expect(screen.getByText(/Loading zones/)).toBeInTheDocument();
      expect(screen.getByText(/Loading zones/)).toHaveClass('duplicate-to-dialog__loading');
    });

    it('shows "Loading containers..." before container data arrives', async () => {
      const user = userEvent.setup();

      function createUrlResponse(body: unknown): Response {
        return {
          ok: true,
          status: 200,
          statusText: 'OK',
          json: () => Promise.resolve(body),
          headers: new Headers(),
          redirected: false,
          type: 'basic' as ResponseType,
          url: '',
          clone() {
            return this;
          },
          body: null,
          bodyUsed: false,
          arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
          blob: () => Promise.resolve(new Blob()),
          bytes: () => Promise.resolve(new Uint8Array()),
          formData: () => Promise.resolve(new FormData()),
          text: () => Promise.resolve(JSON.stringify(body)),
        } as Response;
      }

      vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: string | URL | Request) => {
        const url =
          typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;

        if (url.includes('/api/pages')) return createUrlResponse(PAGES);
        if (url.includes('/api/zones/')) return createUrlResponse(ZONES);

        // Containers never resolve
        return new Promise(() => {});
      });

      renderDialog({ elementType: 'row' });

      await goToPageStep();
      await user.click(screen.getByText('About'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument();
      });

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-container')).toBeInTheDocument();
      });

      expect(screen.getByText(/Loading containers/)).toBeInTheDocument();
      expect(screen.getByText(/Loading containers/)).toHaveClass('duplicate-to-dialog__loading');
    });
  });

  describe('step titles', () => {
    it('shows "Select target page" on page step', () => {
      mockApiRoutes();
      renderDialog();

      expect(screen.getByText('Select target page')).toBeInTheDocument();
    });

    it('shows "Select zone" on zone step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await selectPageAndAdvance(user);

      await waitFor(() => {
        expect(screen.getByText('Select zone')).toBeInTheDocument();
      });
    });

    it('shows "Select container" on container step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      expect(screen.getByText('Select container')).toBeInTheDocument();
    });

    it('shows "Confirm duplication" on confirm step', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'section' });

      await goToZoneStep(user);
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });
      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByText('Confirm duplication')).toBeInTheDocument();
      });
    });
  });

  describe('back button clears selections', () => {
    it('going back from zone clears selectedZone', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog();

      await goToZoneStep(user);
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      // Select a zone
      await user.click(screen.getByText('sidebar'));
      expect(screen.getByText('sidebar').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      );

      // Go back
      await user.click(screen.getByTestId('duplicate-to-back'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-page')).toBeInTheDocument();
      });

      // Advance again — zone should not be pre-selected
      await user.click(screen.getByText('About'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });

      // Next button should be disabled (no zone selected)
      expect(screen.getByTestId('duplicate-to-next')).toBeDisabled();
    });

    it('going back from container clears selectedContainerId', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      // Select a container
      await user.click(screen.getByText('Row 1'));
      expect(screen.getByText('Row 1').closest('[role="option"]')).toHaveAttribute(
        'aria-selected',
        'true',
      );

      // Go back to zone
      await user.click(screen.getByTestId('duplicate-to-back'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-zone')).toBeInTheDocument();
      });

      // Go forward again
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });
      await user.click(screen.getByText('main'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      // Confirm should be disabled (no container selected after back)
      expect(screen.getByTestId('duplicate-to-confirm')).toBeDisabled();
    });
  });

  describe('container item metadata', () => {
    it('displays container type alongside title', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'row' });

      await goToContainerStep(user);

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-container-list')).toBeInTheDocument();
      });

      // Each container shows both title and type
      expect(screen.getByText('Row 1')).toBeInTheDocument();
      expect(screen.getAllByText('Row')).toHaveLength(2);
    });
  });

  describe('confirm step summary', () => {
    it('shows selected zone name in summary', async () => {
      const user = userEvent.setup();
      mockApiRoutes();
      renderDialog({ elementType: 'section' });

      await goToZoneStep(user);
      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-zone-list')).toBeInTheDocument();
      });
      await user.click(screen.getByText('sidebar'));
      await user.click(screen.getByTestId('duplicate-to-next'));

      await waitFor(() => {
        expect(screen.getByTestId('duplicate-to-step-confirm')).toBeInTheDocument();
      });

      const summary = screen.getByText(/Duplicate section to zone/);
      expect(summary).toBeInTheDocument();
      expect(screen.getByText('sidebar')).toBeInTheDocument();
    });
  });

  describe('error and cancel', () => {
    it('displays error message when error prop is set', () => {
      mockApiRoutes();
      renderDialog({ error: 'Something went wrong' });

      expect(screen.getByTestId('duplicate-to-error')).toHaveTextContent('Something went wrong');
    });

    it('does not show error when error is null', () => {
      mockApiRoutes();
      renderDialog({ error: null });

      expect(screen.queryByTestId('duplicate-to-error')).not.toBeInTheDocument();
    });

    it('onCancel called when cancel button clicked', async () => {
      const user = userEvent.setup();
      const onCancel = vi.fn();
      mockApiRoutes();
      renderDialog({ onCancel });

      await user.click(screen.getByText('Cancel'));

      expect(onCancel).toHaveBeenCalledOnce();
    });
  });
});
