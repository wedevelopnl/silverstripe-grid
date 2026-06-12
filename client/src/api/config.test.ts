import { describe, expect, it } from 'vitest'
import { getAdapterConfig, getConfig, getControllerLink, getSecurityId } from './config'

describe('getConfig', () => {
  it('returns the CMS config from window.ss.config', () => {
    const config = getConfig()
    expect(config.SecurityID).toBe('test-security-id')
    expect(config.sections).toHaveLength(1)
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
  it('returns the controller base URL with trailing slash stripped', () => {
    expect(getControllerLink()).toBe('/admin/grid')
  })

  it('throws ConfigError with exact message when controller section is missing', () => {
    window.ss!.config.sections = []

    expect(() => getControllerLink()).toThrow(
      'Controller section "WeDevelop\\Grid\\Controllers\\GridController" not found in CMS config. Ensure the grid module is installed.',
    )
  })

  it('strips multiple trailing slashes from controller link', () => {
    window.ss!.config.sections[0].controllerLink = '/admin/grid///'

    expect(getControllerLink()).toBe('/admin/grid')
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
