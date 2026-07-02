import { render } from '@testing-library/react'
import { Component, type ErrorInfo, type ReactElement, type ReactNode } from 'react'
import { vi } from 'vitest'

interface CaptureProps {
  onError: (error: Error) => void
  children: ReactNode
}

/**
 * Boundary that catches a thrown render and renders nothing. Catching the error
 * is what keeps React from re-dispatching it as an uncaught window `error`
 * event (which vitest prints as a stray `uncaughtException`).
 */
interface CaptureState {
  failed: boolean
}

class ErrorCapture extends Component<CaptureProps, CaptureState> {
  state: CaptureState = { failed: false }

  static getDerivedStateFromError(): CaptureState {
    return { failed: true }
  }

  componentDidCatch(error: Error, _info: ErrorInfo): void {
    this.props.onError(error)
  }

  render(): ReactNode {
    return this.state.failed ? null : this.props.children
  }
}

/**
 * Render `ui` expecting it to throw during render, and return the captured
 * error for assertion. Unlike `expect(() => render(ui)).toThrow()`, this
 * contains the throw in an error boundary and suppresses the console
 * diagnostics React emits, so an intentional "throws outside its provider"
 * test asserts cleanly without bleeding an error into the suite output.
 *
 * Throws if `ui` renders successfully (the expected error never occurred).
 */
export function renderExpectingError(ui: ReactElement): Error {
  let captured: Error | null = null

  // React 18 dev re-dispatches the render throw as a window `error` event, which
  // vitest's harness prints as a stray uncaughtException UNLESS a user `error`
  // listener is registered (it counts them to decide whether to report). This
  // preventing listener keeps that channel quiet; the console.error spy absorbs
  // the diagnostics React logs alongside it (and shadows the fail-on-console
  // wrapper so it is not counted as a failure).
  const swallowWindowError = (event: Event): void => {
    event.preventDefault()
  }
  window.addEventListener('error', swallowWindowError)
  const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

  try {
    render(
      <ErrorCapture
        onError={(error) => {
          captured = error
        }}
      >
        {ui}
      </ErrorCapture>,
    )
  } finally {
    consoleErrorSpy.mockRestore()
    window.removeEventListener('error', swallowWindowError)
  }

  if (captured === null) {
    throw new Error('Expected the rendered component to throw during render, but it did not.')
  }

  return captured
}
