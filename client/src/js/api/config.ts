import type { SilverStripeConfig } from '@/types/silverstripe'
import { ConfigError } from './errors'

const CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\GridController'

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
 * Returns the GridController section from CMS config.
 *
 * @throws ConfigError if the controller section is missing
 */
function getControllerSection() {
  const config = getConfig()
  const section = config.sections.find((s) => s.name === CONTROLLER_FQCN)

  if (section === undefined) {
    throw new ConfigError(
      `Controller section "${CONTROLLER_FQCN}" not found in CMS config. ` +
        'Ensure the grid module is installed.',
    )
  }

  return section
}

/**
 * Returns the base URL for the GridController API, without trailing slash —
 * normalised at the source by GridController::getClientConfig().
 *
 * @throws ConfigError if config is not available or the controller section is missing
 */
export function getControllerLink(): string {
  return getControllerSection().controllerLink
}

/**
 * Returns the grid adapter configuration from the CMS controller section.
 *
 * @throws ConfigError if config is not available or the adapter config is missing
 */
export function getAdapterConfig() {
  const config = getControllerSection().gridAdapter

  if (config === undefined) {
    throw new ConfigError(
      'Grid adapter configuration is missing. ' +
        'Ensure the grid module is installed and configured.',
    )
  }

  return config
}
