import { describe, expect, it } from 'vitest'
import {
  getAdapterConfig,
  getConfig,
  getControllerLink,
  getSecurityId,
  getSharedBlockControllerLink,
} from './config'

describe('getConfig', () => {
  it('returns the CMS config from window.ss.config', () => {
    const config = getConfig()
    expect(config.SecurityID).toBe('test-security-id')
    expect(config.sections).toHaveLength(2)
  })

  it('throws ConfigError with exact message when window.ss.config is undefined', () => {
    delete window.ss

    expect(() => getConfig()).toThrow(
      'SilverStripe config is not available. Ensure the admin bundle is loaded before the grid editor.',
    )
  })
})

describe('getSecurityId', () => {
  it('returns the CSRF security token', () => {
    expect(getSecurityId()).toBe('test-security-id')
  })
})

describe('getControllerLink', () => {
  it('returns the controller base URL', () => {
    expect(getControllerLink()).toBe('/admin/grid')
  })

  it('throws ConfigError with exact message when controller section is missing', () => {
    window.ss!.config.sections = []

    expect(() => getControllerLink()).toThrow(
      'Controller section "WeDevelop\\Grid\\Controllers\\GridController" not found in CMS config. Ensure the grid module is installed.',
    )
  })

  it('throws ConfigError when only a differently-named section is present', () => {
    // A non-matching section must not be returned: the section is selected by
    // an exact name match, not merely by being the first entry.
    window.ss!.config.sections[0].name = 'Some\\Other\\Controller'

    expect(() => getControllerLink()).toThrow(
      'Controller section "WeDevelop\\Grid\\Controllers\\GridController" not found in CMS config. Ensure the grid module is installed.',
    )
  })
})

describe('getSharedBlockControllerLink', () => {
  it('returns the block library controller base URL, not the grid one', () => {
    expect(getSharedBlockControllerLink()).toBe('/admin/grid-shared-blocks')
    expect(getSharedBlockControllerLink()).not.toBe(getControllerLink())
  })

  it('throws ConfigError naming the shared block controller when its section is missing', () => {
    // Removing only the block section must not fall back to the grid section:
    // the two controllers live under different admin URL segments, so a silent
    // fallback would send every block request to the wrong controller.
    window.ss!.config.sections = window.ss!.config.sections.filter(
      (s) => s.name === 'WeDevelop\\Grid\\Controllers\\GridController',
    )

    expect(() => getSharedBlockControllerLink()).toThrow(
      'Controller section "WeDevelop\\Grid\\Controllers\\SharedBlockController" not found in CMS config. Ensure the grid module is installed.',
    )
  })
})

describe('getAdapterConfig', () => {
  it('returns the grid adapter configuration', () => {
    const config = getAdapterConfig()
    expect(config.columnCount).toBe(12)
    expect(config.defaultViewport).toBe('md')
  })

  it('throws ConfigError with exact message when adapter config is missing', () => {
    delete window.ss!.config.sections[0].gridAdapter

    expect(() => getAdapterConfig()).toThrow(
      'Grid adapter configuration is missing. Ensure the grid module is installed and configured.',
    )
  })
})
