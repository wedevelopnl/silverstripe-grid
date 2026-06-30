import type { ErrorInfo, ReactNode } from 'react'
import { Component } from 'react'
import { t } from '@/i18n'
import { showToast } from '@/utils/toast'

interface Props {
  readonly children: ReactNode
}

interface State {
  hasError: boolean
}

/**
 * Error boundary that catches render-time exceptions in the grid editor
 * React tree. Shows a toast notification and renders a static fallback
 * instead of crashing the entire CMS panel.
 */
export default class GridEditorErrorBoundary extends Component<Props, State> {
  override state: State = { hasError: false }

  static getDerivedStateFromError(): State {
    return { hasError: true }
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — logs the caught render error alongside the user-facing toast.
    console.error('[GridEditor] Render error:', error, info)
    showToast(
      t(
        'WeDevelopGrid.GridEditorErrorBoundary.TOAST_ERROR',
        'The grid editor encountered an error and could not render.',
      ),
    )
  }

  override render(): ReactNode {
    if (this.state.hasError) {
      return (
        <p data-testid="grid-editor-error">
          {t(
            'WeDevelopGrid.GridEditorErrorBoundary.RENDER_FALLBACK',
            'The grid editor failed to render. Try reloading the page.',
          )}
        </p>
      )
    }

    return this.props.children
  }
}
