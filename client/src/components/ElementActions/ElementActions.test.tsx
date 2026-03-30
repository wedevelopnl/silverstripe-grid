import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createSimpleElement } from '@/testing/factories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import ElementActions from './ElementActions';

describe('ElementActions', () => {
  it('renders actions menu with correct actions for element with all permissions', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    const node = createSimpleElement({
      canDelete: true,
      canCreate: true,
    });

    renderWithProviders(<ElementActions node={node} />);

    // Open the actions menu
    await user.click(screen.getByTestId('actions-menu-trigger'));

    // Should have Duplicate, Duplicate to, and Archive actions
    expect(screen.getByText('Duplicate')).toBeInTheDocument();
    expect(screen.getByText('Archive')).toBeInTheDocument();
  });

  it('no actions when canDelete and canCreate are false', () => {
    mockFetchSuccess({});

    const node = createSimpleElement({
      canDelete: false,
      canCreate: false,
    });

    renderWithProviders(<ElementActions node={node} />);

    // ActionsMenu returns null when actions array is empty
    expect(screen.queryByTestId('actions-menu-trigger')).not.toBeInTheDocument();
  });
});
