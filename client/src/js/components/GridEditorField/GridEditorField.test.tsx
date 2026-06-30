import { render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import GridEditorField from './GridEditorField'

describe('GridEditorField (FormBuilder entry point)', () => {
  it('renders the grid editor with pageId and zone from schema data', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main' }}
        readOnly={false}
      />,
    )

    const gridEditor = screen.getByTestId('grid-editor')
    expect(gridEditor).toHaveAttribute('data-page-id', '42')
    expect(gridEditor).toHaveAttribute('data-zone', 'main')
  })

  it('renders in readonly mode when FormBuilder sets readOnly=true', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main', version: 5 }}
        readOnly={true}
      />,
    )

    expect(screen.getByTestId('grid-editor')).toHaveAttribute('data-readonly', '')
  })

  it('returns null when pageId is missing from schema data', () => {
    const { container } = render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{}}
        readOnly={false}
      />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('defaults the zone to "main" when schema data omits it', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42 }}
        readOnly={false}
      />,
    )

    expect(screen.getByTestId('grid-editor')).toHaveAttribute('data-zone', 'main')
  })

  it('does not render in readonly mode when neither readOnly nor data.readonly is set', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main' }}
        readOnly={false}
      />,
    )

    expect(screen.getByTestId('grid-editor')).not.toHaveAttribute('data-readonly')
  })

  it('renders readonly when FormBuilder readOnly is false but data.readonly is true', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main', readonly: true }}
        readOnly={false}
      />,
    )

    expect(screen.getByTestId('grid-editor')).toHaveAttribute('data-readonly', '')
  })

  it('requests the version-specific tree when readonly with a positive version', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main', version: 5 }}
        readOnly={true}
      />,
    )

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalled()
    })

    const requestedUrl = String(fetchSpy.mock.calls[0]?.[0])
    expect(requestedUrl).toContain('/version/5')
  })

  it('omits the version segment when version is zero (non-positive)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    render(
      <GridEditorField
        name="GridEditor"
        id="Form_versionForm_GridEditor"
        data={{ pageId: 42, zone: 'main', version: 0 }}
        readOnly={true}
      />,
    )

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalled()
    })

    const requestedUrl = String(fetchSpy.mock.calls[0]?.[0])
    expect(requestedUrl).not.toContain('/version/')
  })
})
