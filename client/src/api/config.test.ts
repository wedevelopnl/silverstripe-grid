import { describe, it, expect } from 'vitest';
import { getConfig, getSecurityId, getControllerLink, getAdapterConfig } from './config';

describe('getConfig', () => {
  it('returns the CMS config from window.ss.config', () => {
    const config = getConfig();
    expect(config.SecurityID).toBe('test-security-id');
    expect(config.sections).toHaveLength(1);
  });

  it('throws ConfigError when window.ss.config is undefined', () => {
    // @ts-expect-error — testing missing global
    delete window.ss;

    expect(() => getConfig()).toThrow('SilverStripe config is not available');
  });
});

describe('getSecurityId', () => {
  it('returns the CSRF security token', () => {
    expect(getSecurityId()).toBe('test-security-id');
  });
});

describe('getControllerLink', () => {
  it('returns the controller base URL with trailing slash stripped', () => {
    expect(getControllerLink()).toBe('/admin/grid');
  });

  it('throws ConfigError when controller section is missing', () => {
    window.ss.config.sections = [];

    expect(() => getControllerLink()).toThrow('Controller section');
  });
});

describe('getAdapterConfig', () => {
  it('returns the grid adapter configuration', () => {
    const config = getAdapterConfig();
    expect(config.columnCount).toBe(12);
    expect(config.defaultViewport).toBe('md');
  });

  it('throws ConfigError when adapter config is missing', () => {
    delete window.ss.config.sections[0].gridAdapter;

    expect(() => getAdapterConfig()).toThrow('Grid adapter configuration is missing');
  });
});
