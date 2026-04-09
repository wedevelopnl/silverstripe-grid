/**
 * Type declarations for SilverStripe CMS globals consumed by the grid editor.
 *
 * The admin module exposes shared JS modules as window globals via webpack
 * externals. We access these via `window` rather than ES imports to avoid
 * needing Rollup external mappings for non-React globals.
 */

import type { ComponentType } from 'react';
import type { AdapterConfig } from './adapter';

// --- Injector (lib/Injector) ---

export interface InjectorComponentRegistry {
  // Components are registered with their own prop types but retrieved
  // generically — the registry accepts any component signature.
  // biome-ignore lint/suspicious/noExplicitAny: ComponentType props are contravariant; unknown rejects real components.
  registerMany(components: Record<string, ComponentType<any>>): void;
}

export interface InjectorContainer {
  component: InjectorComponentRegistry;
}

interface InjectorGlobal {
  /** The singleton Container instance */
  default: InjectorContainer;
  /** Load a component with all registered transforms applied */
  // biome-ignore lint/suspicious/noExplicitAny: generic component loader; callers cast to the specific component type.
  loadComponent(name: string, context?: Record<string, unknown>): ComponentType<any>;
}

// --- CMS config (window.ss.config) ---

export interface SilverStripeSectionConfig {
  name: string;
  url: string;
  controllerLink: string;
  gridAdapter?: AdapterConfig;
  [key: string]: unknown;
}

export interface SilverStripeConfig {
  SecurityID: string;
  sections: SilverStripeSectionConfig[];
}

// --- Window augmentation (jQuery, entwine, Injector, CMS config) ---

declare global {
  interface EntwineRules {
    onmatch?(this: JQueryEntwineElement): void;
    onunmatch?(this: JQueryEntwineElement): void;
    onchange?(this: JQueryEntwineElement): void;
    onclick?(this: JQueryEntwineElement): void;
    [key: string]: unknown;
  }

  interface JQueryEntwineElement {
    data(key: string): unknown;
    entwine(rules: EntwineRules): void;
    getReactRoot(): import('react-dom/client').Root | null;
    setReactRoot(root: import('react-dom/client').Root | null): void;
    closest(selector: string): JQueryEntwineElement;
    find(selector: string): JQueryEntwineElement;
    not(selector: string): JQueryEntwineElement;
    is(selector: string): boolean;
    prop(name: string, value: unknown): JQueryEntwineElement;
    val(): string | number | string[] | undefined;
    toggle(showOrHide: boolean): JQueryEntwineElement;
    trigger(eventType: string, extraParameters?: unknown): JQueryEntwineElement;
    addClass(className: string): JQueryEntwineElement;
    removeClass(className: string): JQueryEntwineElement;
    toggleClass(className: string, state: boolean): JQueryEntwineElement;
    length: number;
    _super(): void;
    [index: number]: HTMLElement;
  }

  interface JQueryStatic {
    (selector: string): JQueryEntwineElement;
    (element: JQueryEntwineElement): JQueryEntwineElement;
    entwine(namespace: string, callback: ($: JQueryStatic) => void): void;
  }

  interface Window {
    Injector: InjectorGlobal;
    jQuery: JQueryStatic;
    ss: {
      config: SilverStripeConfig;
      store?: { dispatch(action: unknown): void };
    };
  }
}
