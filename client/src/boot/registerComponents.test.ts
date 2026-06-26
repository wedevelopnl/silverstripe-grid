import { describe, expect, it, vi } from 'vitest'
import GridEditor from '@/components/GridEditor/GridEditor'
import GridEditorField from '@/components/GridEditorField/GridEditorField'

const registerMany = vi.fn()
vi.mock('@/bridge/Injector', () => ({
  getInjector: () => ({ component: { registerMany } }),
}))

import { registerComponents } from './registerComponents'

describe('registerComponents', () => {
  it('registers exactly GridEditor and GridEditorField with the Injector', () => {
    registerComponents()

    expect(registerMany).toHaveBeenCalledTimes(1)
    expect(registerMany).toHaveBeenCalledWith({ GridEditor, GridEditorField })
  })
})
