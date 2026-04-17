// @ts-check

/**
 * Stryker ignorer plugins for false-positive mutants.
 *
 * Suppresses mutations that survive because they target patterns whose
 * correctness cannot be observed in unit tests:
 * - React hook semantics (dep arrays, effect cleanups)
 * - jsdom limitations (HTMLDialogElement.showModal/close)
 * - Visual-only CSS classes inside className template literals
 * - i18n translation keys (first arg of `t(…)` calls — tests assert the
 *   rendered fallback string, not the key)
 *
 * Exported as two plugins (`react` and `i18nKey`) so they can be toggled
 * independently via `ignorers` in stryker.config.mjs.
 *
 * @see https://stryker-mutator.io/docs/stryker-js/disable-mutants/
 */

/** @type {ReadonlySet<string>} */
const HOOK_NAMES = new Set([
  'useCallback',
  'useMemo',
  'useEffect',
  'useLayoutEffect',
]);

/** @type {ReadonlySet<string>} */
const EFFECT_HOOKS = new Set(['useEffect', 'useLayoutEffect']);

/**
 * Walk ancestors to find the nearest CallExpression whose callee name
 * is in the given set. Returns the callee name or null.
 *
 * @param {import('@stryker-mutator/api/ignore').NodePath} path
 * @param {ReadonlySet<string>} names
 * @returns {string | null}
 */
function findAncestorHookCall(path, names) {
  let current = path.parentPath;
  while (current) {
    if (
      current.isCallExpression() &&
      current.node.callee.type === 'Identifier' &&
      names.has(current.node.callee.name)
    ) {
      return current.node.callee.name;
    }
    current = current.parentPath;
  }
  return null;
}

/**
 * Check whether a node contains a MemberExpression call to `.showModal()`
 * or `.close()` anywhere in its subtree. Uses a simple recursive walk
 * instead of Babel's traverse (which isn't available on the raw node).
 *
 * @param {object} node
 * @returns {boolean}
 */
function containsDialogSyncCall(node) {
  if (!node || typeof node !== 'object') return false;

  if (
    node.type === 'CallExpression' &&
    node.callee?.type === 'MemberExpression' &&
    node.callee.property?.type === 'Identifier' &&
    (node.callee.property.name === 'showModal' ||
      node.callee.property.name === 'close')
  ) {
    return true;
  }

  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        if (item && typeof item === 'object' && typeof item.type === 'string') {
          if (containsDialogSyncCall(item)) return true;
        }
      }
    } else if (value && typeof value === 'object' && typeof value.type === 'string') {
      if (containsDialogSyncCall(value)) return true;
    }
  }

  return false;
}

/**
 * Check if the path is inside a TemplateLiteral within a className JSX attribute.
 *
 * @param {import('@stryker-mutator/api/ignore').NodePath} path
 * @returns {boolean}
 */
function isInsideClassNameTemplateLiteral(path) {
  let current = path.parentPath;
  let insideTemplate = false;
  while (current) {
    if (current.isTemplateLiteral()) {
      insideTemplate = true;
    }
    if (
      insideTemplate &&
      current.isJSXAttribute() &&
      current.node.name?.type === 'JSXIdentifier' &&
      current.node.name.name === 'className'
    ) {
      return true;
    }
    current = current.parentPath;
  }
  return false;
}

/** @type {import('@stryker-mutator/api/ignore').Ignorer} */
const reactIgnorer = {
  shouldIgnore(path) {
    // Pattern 1: React hook dependency arrays
    // Matches the last argument (ArrayExpression) of useCallback/useMemo/useEffect/useLayoutEffect
    if (path.isArrayExpression() && path.parentPath?.isCallExpression()) {
      const callArgs = path.parentPath.node.arguments;
      const callee = path.parentPath.node.callee;

      if (
        callee.type === 'Identifier' &&
        HOOK_NAMES.has(callee.name) &&
        callArgs.length >= 2 &&
        callArgs[callArgs.length - 1] === path.node
      ) {
        return 'React hook dependency array';
      }
    }

    // Pattern 2: CSS className mutations inside TemplateLiterals
    // Suppresses StringLiteral mutations (modifier classes, empty branches) and
    // ConditionalExpression mutations (selected/disabled toggles) inside className
    // JSX attributes — these are visual-only and don't affect testable behavior.
    if (path.isStringLiteral() && isInsideClassNameTemplateLiteral(path)) {
      return 'CSS className string in template literal';
    }
    if (path.isConditionalExpression() && isInsideClassNameTemplateLiteral(path)) {
      return 'CSS className conditional in template literal';
    }

    // Pattern 3: useEffect/useLayoutEffect cleanup return functions
    // Matches ArrowFunctionExpression returned from an effect callback
    if (
      path.isArrowFunctionExpression() &&
      path.parentPath?.isReturnStatement() &&
      findAncestorHookCall(path, EFFECT_HOOKS) !== null
    ) {
      return 'useEffect cleanup function';
    }

    // Pattern 4: Dialog showModal/close sync inside useEffect
    // Matches IfStatement containing .showModal() or .close() calls
    // inside a useEffect callback (untestable in jsdom)
    if (
      path.isIfStatement() &&
      containsDialogSyncCall(path.node) &&
      findAncestorHookCall(path, EFFECT_HOOKS) !== null
    ) {
      return 'Dialog showModal/close sync (jsdom limitation)';
    }

    return undefined;
  },
};

/**
 * Ignore StringLiteral mutations in the first argument of `t(…)` calls.
 *
 * The i18n helper `t(key, fallback)` returns the fallback in tests because
 * `window.ss.i18n._t` is mocked to echo the fallback. Component tests assert
 * the rendered fallback, so mutating the key to "" is semantically invisible.
 * Skip these centrally rather than sprinkling disable comments over ~60 call
 * sites.
 */
/** @type {import('@stryker-mutator/api/ignore').Ignorer} */
const i18nKeyIgnorer = {
  shouldIgnore(path) {
    if (!path.isStringLiteral()) return undefined;

    const parent = path.parentPath;
    if (!parent?.isCallExpression()) return undefined;

    const callee = parent.node.callee;
    if (callee.type !== 'Identifier' || callee.name !== 't') return undefined;

    if (parent.node.arguments[0] !== path.node) return undefined;

    return 'i18n translation key (first arg of t() call)';
  },
};

/** @type {import('@stryker-mutator/api/plugin').ValuePlugin<import('@stryker-mutator/api/plugin').PluginKind.Ignore>} */
const reactPlugin = {
  kind: 'Ignore',
  name: 'react',
  value: reactIgnorer,
};

/** @type {import('@stryker-mutator/api/plugin').ValuePlugin<import('@stryker-mutator/api/plugin').PluginKind.Ignore>} */
const i18nPlugin = {
  kind: 'Ignore',
  name: 'i18nKey',
  value: i18nKeyIgnorer,
};

export const strykerPlugins = [reactPlugin, i18nPlugin];
