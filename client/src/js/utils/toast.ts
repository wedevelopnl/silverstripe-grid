/**
 * Show an error toast in the CMS. Every caller reports a failure, so the toast
 * is always an error and always stays until dismissed — add a variant parameter
 * back when the first success/warning caller arrives.
 */
export function showToast(text: string): void {
  const store = window.ss?.store
  if (store === undefined) {
    // biome-ignore lint/suspicious/noConsole: intentional fallback diagnostic — emits the toast to the console when the CMS toast store is unavailable.
    console.warn(`[GridEditor] error: ${text}`)
    return
  }

  store.dispatch({
    type: 'DISPLAY_TOAST',
    payload: {
      id: `toast-${crypto.randomUUID()}`,
      text,
      type: 'error',
      stay: true,
    },
  })
}
