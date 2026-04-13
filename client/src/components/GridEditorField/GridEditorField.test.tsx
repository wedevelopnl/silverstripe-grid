import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import GridEditorField from './GridEditorField';

describe('GridEditorField (FormBuilder entry point)', () => {
  it('renders the grid editor with pageId and zone from schema data', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}));

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main' }}
        readOnly={false}
      />,
    );

    const gridEditor = screen.getByTestId('grid-editor');
    expect(gridEditor).toHaveAttribute('data-page-id', '42');
    expect(gridEditor).toHaveAttribute('data-zone', 'main');
  });

  it('renders in readonly mode when FormBuilder sets readOnly=true', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}));

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main', version: 5 }}
        readOnly={true}
      />,
    );

    expect(screen.getByTestId('grid-editor')).toHaveClass('grid-editor--readonly');
  });

  it('returns null when pageId is missing from schema data', () => {
    const { container } = render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{}}
        readOnly={false}
      />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});
