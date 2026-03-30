import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { mockFetchSuccess, getFetchCalls } from '@/testing/mockFetch';
import { renderWithProviders } from '@/testing/renderWithProviders';

import AddChildButton from './AddChildButton';

describe('AddChildButton', () => {
  it('renders with correct label text', () => {
    mockFetchSuccess({});

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" childLabel="Row" variant="append" />,
    );

    expect(screen.getByTestId('add-child-button')).toHaveTextContent('Add Row');
  });

  it('click triggers createElement mutation with correct containerType and parentId', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" childLabel="Row" variant="append" />,
    );

    await user.click(screen.getByTestId('add-child-button'));

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
    });

    const [, init] = getFetchCalls()[0];
    const body = JSON.parse(init!.body as string);

    expect(body).toMatchObject({ containerType: 'row', parentId: 10 });
    expect(body.zone).toBeUndefined();
  });

  it('section type includes zone in mutation payload', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    renderWithProviders(
      <AddChildButton parentId={1} childType="section" childLabel="Section" variant="append" />,
      { zone: 'main' },
    );

    await user.click(screen.getByTestId('add-child-button'));

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
    });

    const [, init] = getFetchCalls()[0];
    const body = JSON.parse(init!.body as string);

    expect(body).toMatchObject({ containerType: 'section', parentId: 1, zone: 'main' });
  });

  it('shows "Adding..." text during mutation', async () => {
    const user = userEvent.setup();

    // Never-resolving fetch to keep the mutation pending
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}));

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" childLabel="Row" variant="append" />,
    );

    await user.click(screen.getByTestId('add-child-button'));

    await waitFor(() => {
      expect(screen.getByTestId('add-child-button')).toHaveTextContent('Adding Row');
    });
  });

  it('button is disabled during mutation', async () => {
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}));

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" childLabel="Row" variant="append" />,
    );

    await user.click(screen.getByTestId('add-child-button'));

    await waitFor(() => {
      expect(screen.getByTestId('add-child-button')).toBeDisabled();
    });
  });

  it('empty-state variant renders message', () => {
    mockFetchSuccess({});

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" childLabel="Row" variant="empty-state" />,
    );

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument();
    expect(screen.getByText('No rows yet')).toBeInTheDocument();
  });

  it('append variant renders without message', () => {
    mockFetchSuccess({});

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" childLabel="Row" variant="append" />,
    );

    expect(screen.getByTestId('add-child-append')).toBeInTheDocument();
    expect(screen.queryByText('No rows yet')).not.toBeInTheDocument();
  });
});
