/**
 * Yield one macrotask so MutationObserver batches and queued timers run
 * before the next assertion.
 */
export function flushObservers(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}
