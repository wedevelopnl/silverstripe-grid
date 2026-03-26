// @ts-check

/**
 * Stryker ignorer plugin for React-specific false-positive mutants.
 *
 * Suppresses mutations that survive because they target patterns whose
 * correctness cannot be observed in unit tests (jsdom limitations,
 * React hook semantics, visual-only CSS classes).
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
 * Walk ancestors to check if the path is inside a JSX attribute named `className`.
 *
 * @param {import('@stryker-mutator/api/ignore').NodePath} path
 * @returns {boolean}
 */
function isInsideClassNameAttribute(path) {
  let current = path.parentPath;
  while (current) {
    if (
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

    // Pattern 2: CSS className ternary empty-string false branches
    // Matches StringLiteral '' as the alternate of a ConditionalExpression
    // inside a TemplateLiteral within a className JSX attribute
    if (
      path.isStringLiteral() &&
      path.node.value === '' &&
      path.parentPath?.isConditionalExpression() &&
      path.key === 'alternate' &&
      path.parentPath.parentPath?.isTemplateLiteral() &&
      isInsideClassNameAttribute(path.parentPath.parentPath)
    ) {
      return 'CSS className ternary false branch';
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

/** @type {import('@stryker-mutator/api/plugin').ValuePlugin<import('@stryker-mutator/api/plugin').PluginKind.Ignore>} */
const plugin = {
  kind: 'Ignore',
  name: 'react',
  value: reactIgnorer,
};

export const strykerPlugins = [plugin];
