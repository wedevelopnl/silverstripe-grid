import type { SilverStripeConfig } from '@/types/silverstripe'
import { ConfigError } from './errors'

const CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\GridController'
const SHARED_BLOCK_CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\SharedBlockController'

/**
 * Returns the global SilverStripe CMS configuration object.
 *
 * @throws ConfigError if the admin bundle has not loaded
 */
export function getConfig(): SilverStripeConfig {
  const config = window.ss?.config

  if (config === undefined) {
    throw new ConfigError(
      'SilverStripe config is not available. ' +
        'Ensure the admin bundle is loaded before the grid editor.',
    )
  }

  return config
}

/**
 * Returns the CSRF security token from CMS config.
 *
 * @throws ConfigError if config is not available
 */
export function getSecurityId(): string {
  return getConfig().SecurityID
}

/**
 * The CMS bootstraps `config.sections` once per admin page load, but the
 * getters below run per column per render. Cache the found sections keyed on
 * the sections array identity: each scan runs once per bootstrap, and a
 * replaced array (fresh page load, tests reinstalling window.ss.config)
 * invalidates the whole cache naturally.
 */
let sectionCache: {
  sections: SilverStripeConfig['sections']
  byName: Map<string, SilverStripeConfig['sections'][number]>
} | null = null

/**
 * Returns one of the grid module's controller sections from CMS config.
 *
 * @throws ConfigError if the controller section is missing
 */
function getControllerSection(fqcn: string) {
  const config = getConfig()

  if (sectionCache === null || sectionCache.sections !== config.sections) {
    sectionCache = { sections: config.sections, byName: new Map() }
  }

  const cached = sectionCache.byName.get(fqcn)
  if (cached !== undefined) {
    return cached
  }

  const section = config.sections.find((s) => s.name === fqcn)

  if (section === undefined) {
    throw new ConfigError(
      `Controller section "${fqcn}" not found in CMS config. ` +
        'Ensure the grid module is installed.',
    )
  }

  sectionCache.byName.set(fqcn, section)
  return section
}

/**
 * Returns the base URL for the GridController API, without trailing slash —
 * normalised at the source by GridApiController::getClientConfig().
 *
 * @throws ConfigError if config is not available or the controller section is missing
 */
export function getControllerLink(): string {
  return getControllerSection(CONTROLLER_FQCN).controllerLink
}

/**
 * Returns the base URL for the SharedBlockController API. The block library is
 * a separate controller under its own admin URL segment, so its base URL is
 * read from its own section rather than derived from the grid's.
 *
 * @throws ConfigError if config is not available or the controller section is missing
 */
export function getSharedBlockControllerLink(): string {
  return getControllerSection(SHARED_BLOCK_CONTROLLER_FQCN).controllerLink
}

/**
 * Returns the grid adapter configuration from the CMS controller section.
 *
 * @throws ConfigError if config is not available or the adapter config is missing
 */
export function getAdapterConfig() {
  const config = getControllerSection(CONTROLLER_FQCN).gridAdapter

  if (config === undefined) {
    throw new ConfigError(
      'Grid adapter configuration is missing. ' +
        'Ensure the grid module is installed and configured.',
    )
  }

  return config
}
