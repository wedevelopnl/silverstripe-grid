import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import AddChildButton from '@/components/AddChildButton/AddChildButton';
import { createGridEditorWrapper } from '../../helpers/dndTestUtils';

const mockCreateElement = vi.fn();

vi.mock('@/api/endpoints', () => ({
  createElement: (...args: unknown[]) => mockCreateElement(...args),
  archiveElement: vi.fn(),
  duplicateElement: vi.fn(),
  updateGridSettings: vi.fn(),
}));

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
      { wrapper: createGridEditorWrapper().wrapper },
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
      { wrapper: createGridEditorWrapper().wrapper },
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
      { wrapper: createGridEditorWrapper(10, 'main').wrapper },
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
      { wrapper: createGridEditorWrapper(42, 'sidebar').wrapper },
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
      { wrapper: createGridEditorWrapper().wrapper },
    );

    await user.click(screen.getByTestId('add-child-button'));

    expect(screen.getByTestId('add-child-button').getAttribute('disabled')).toBe('');
    expect(screen.getByText('Adding Row…')).toBeDefined();
  });
});
