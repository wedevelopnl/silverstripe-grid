import { registerComponents } from './registerComponents'

document.addEventListener('DOMContentLoaded', () => {
  try {
    registerComponents()
  } catch (error: unknown) {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — surfaces a component-registration failure in the CMS boot path.
    console.warn('[GridEditor] Failed to register components.', error)
  }
})
