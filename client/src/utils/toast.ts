export function showToast(text: string, type: 'error' | 'success' | 'warning' = 'error'): void {
  const store = window.ss?.store
  if (store === undefined) {
    // biome-ignore lint/suspicious/noConsole: intentional fallback diagnostic — emits the toast to the console when the CMS toast store is unavailable.
    console.warn(`[GridEditor] ${type}: ${text}`)
    return
  }

  store.dispatch({
    type: 'DISPLAY_TOAST',
    payload: {
      id: `toast-${crypto.randomUUID()}`,
      text,
      type,
      stay: type !== 'success',
    },
  })
}
