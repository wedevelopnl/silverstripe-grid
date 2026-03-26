import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ElementActions from '@/components/ElementActions/ElementActions';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
import { makeLeaf } from '@/tests/helpers/elementFactories';

vi.mock('@/api/endpoints', () => ({
  archiveElement: vi.fn(),
  duplicateElement: vi.fn(),
  duplicateToElement: vi.fn(),
}));

describe('ElementActions', () => {
  beforeEach(() => {
    HTMLDialogElement.prototype.showModal = vi.fn();
    HTMLDialogElement.prototype.close = vi.fn();
  });

  it('renders actions menu when permissions allow', () => {
    render(<ElementActions node={makeLeaf()} />, {
      wrapper: createDndWrapper(),
    });

    expect(screen.getByTestId('actions-menu-trigger')).toBeDefined();
  });

  it('renders all three actions in correct order', async () => {
    const user = userEvent.setup();

    render(<ElementActions node={makeLeaf()} />, {
      wrapper: createDndWrapper(),
    });

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const items = screen.getAllByRole('menuitem');
    expect(items).toHaveLength(3);
    expect(items[0].textContent).toBe('Duplicate');
    expect(items[1].textContent).toBe('Duplicate to\u2026');
    expect(items[2].textContent).toBe('Archive');
  });

  it('renders no actions menu when all permissions are denied', () => {
    render(<ElementActions node={makeLeaf({ canDelete: false, canCreate: false })} />, {
      wrapper: createDndWrapper(),
    });

    expect(screen.queryByTestId('actions-menu-trigger')).toBeNull();
  });

  it('renders only archive when canCreate is false', async () => {
    const user = userEvent.setup();

    render(<ElementActions node={makeLeaf({ canCreate: false })} />, {
      wrapper: createDndWrapper(),
    });

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const items = screen.getAllByRole('menuitem');
    expect(items).toHaveLength(1);
    expect(items[0].textContent).toBe('Archive');
  });

  it('renders only duplicate actions when canDelete is false', async () => {
    const user = userEvent.setup();

    render(<ElementActions node={makeLeaf({ canDelete: false })} />, {
      wrapper: createDndWrapper(),
    });

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const items = screen.getAllByRole('menuitem');
    expect(items).toHaveLength(2);
    expect(items[0].textContent).toBe('Duplicate');
    expect(items[1].textContent).toBe('Duplicate to\u2026');
  });

  it('opens archive dialog when archive action is triggered', async () => {
    const user = userEvent.setup();

    render(<ElementActions node={makeLeaf()} />, {
      wrapper: createDndWrapper(),
    });

    await user.click(screen.getByTestId('actions-menu-trigger'));
    await user.click(screen.getByRole('menuitem', { name: 'Archive' }));

    expect(screen.getByTestId('confirm-dialog')).toBeDefined();
  });

  it('opens duplicate-to dialog when duplicate-to action is triggered', async () => {
    const user = userEvent.setup();

    render(<ElementActions node={makeLeaf()} />, {
      wrapper: createDndWrapper(),
    });

    await user.click(screen.getByTestId('actions-menu-trigger'));
    await user.click(screen.getByRole('menuitem', { name: 'Duplicate to\u2026' }));

    expect(screen.getByTestId('duplicate-to-dialog')).toBeDefined();
  });
});
