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
 * Exported as three plugins (`react`, `i18nKey`, and `mutationResidue`) so they
 * can be toggled independently via `ignorers` in stryker.config.mjs. The
 * `mutationResidue` plugin centralizes three formerly-inline `// Stryker disable`
 * families (TanStack enabled-flag args, `stopPropagation` calls, applyReorder's
 * sourceIndex guard) — see its docblock below.
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

/**
 * Check if the path is the entire value of a `className` JSX attribute —
 * either `className="literal"` (StringLiteral directly on the attribute) or
 * `className={`...`}` / `className={'...'}` (literal/template wrapped in a
 * JSXExpressionContainer).
 *
 * @param {import('@stryker-mutator/api/ignore').NodePath} path
 * @returns {boolean}
 */
function isClassNameAttributeValue(path) {
  const parent = path.parentPath;
  if (!parent) return false;

  // className="..."
  if (
    parent.isJSXAttribute() &&
    parent.node.name?.type === 'JSXIdentifier' &&
    parent.node.name.name === 'className'
  ) {
    return true;
  }

  // className={`...`} or className={'...'}
  if (
    parent.isJSXExpressionContainer() &&
    parent.parentPath?.isJSXAttribute() &&
    parent.parentPath.node.name?.type === 'JSXIdentifier' &&
    parent.parentPath.node.name.name === 'className'
  ) {
    return true;
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

    // Pattern 2b: the className value *itself* (whole StringLiteral or whole
    // TemplateLiteral). Pattern 2 only catches literals *nested inside* a
    // className template (the conditional class arms); Stryker also empties the
    // entire `className={`...`}` / `className="..."` value (reported as a
    // StringLiteral mutation to ``). That changes only the CSS class string —
    // visual-only, not a behavioral contract — so it cannot be killed by a good
    // test (components assert via role/text/testid/data-* attributes, not class
    // names). Same rationale as Pattern 2.
    if (
      (path.isStringLiteral() || path.isTemplateLiteral()) &&
      isClassNameAttributeValue(path)
    ) {
      return 'CSS className attribute value';
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

/** @type {ReadonlySet<string>} */
const TANSTACK_ENABLED_HOOKS = new Set([
  'usePages',
  'useZones',
  'useAcceptableContainers',
]);

/**
 * True when `path` is positioned inside the enabled-flag argument of a
 * CallExpression whose callee Identifier is one of the TanStack enabled-flag
 * hooks (usePages/useZones/useAcceptableContainers). Walks ancestors, and at
 * each CallExpression to a matching hook confirms the path's subtree is reached
 * *through* one of that call's arguments — AND that the ascended argument node
 * is itself a BinaryExpression or ConditionalExpression (the `step === 'page'` /
 * `step !== 'page' ? id : null` enabled-flag shape). This excludes the bare
 * first-arg Identifier (e.g. `debouncedSearch`) and the hook call itself, so
 * only the documented enabled-flag mutant surface matches.
 *
 * @param {import('@stryker-mutator/api/ignore').NodePath} path
 * @returns {boolean}
 */
function isTanStackEnabledArgument(path) {
  let current = path.parentPath;
  let previous = path;
  while (current) {
    if (
      current.isCallExpression() &&
      current.node.callee.type === 'Identifier' &&
      TANSTACK_ENABLED_HOOKS.has(current.node.callee.name) &&
      current.node.arguments.some(
        (arg) =>
          arg === previous.node &&
          (arg.type === 'BinaryExpression' ||
            arg.type === 'ConditionalExpression'),
      )
    ) {
      return true;
    }
    previous = current;
    current = current.parentPath;
  }
  return false;
}

/**
 * True when `path` is, or is nested inside, a CallExpression of the form
 * `<expr>.stopPropagation()`. Walks ancestors (including the path itself) so a
 * mutant on the call, its callee MemberExpression, or any argument is covered.
 *
 * @param {import('@stryker-mutator/api/ignore').NodePath} path
 * @returns {boolean}
 */
function isStopPropagationCall(path) {
  /** @type {import('@stryker-mutator/api/ignore').NodePath | null} */
  let current = path;
  while (current) {
    if (
      current.isCallExpression() &&
      current.node.callee.type === 'MemberExpression' &&
      current.node.callee.property.type === 'Identifier' &&
      current.node.callee.property.name === 'stopPropagation'
    ) {
      return true;
    }
    current = current.parentPath;
  }
  return false;
}

/**
 * True when `node` is the binary test `sourceIndex === undefined`
 * (`applyReorder`'s source-index guard).
 *
 * @param {object} node
 * @returns {boolean}
 */
function isSourceIndexUndefinedTest(node) {
  return (
    node?.type === 'BinaryExpression' &&
    node.operator === '===' &&
    node.left?.type === 'Identifier' &&
    node.left.name === 'sourceIndex' &&
    node.right?.type === 'Identifier' &&
    node.right.name === 'undefined'
  );
}

/**
 * True when the IfStatement's consequent is exactly `return tree` (either a bare
 * ReturnStatement or a single-statement block wrapping it).
 *
 * @param {object} consequent
 * @returns {boolean}
 */
function isReturnTreeConsequent(consequent) {
  if (!consequent || typeof consequent !== 'object') return false;

  /** @type {object | undefined} */
  let returnStatement;
  if (consequent.type === 'ReturnStatement') {
    returnStatement = consequent;
  } else if (
    consequent.type === 'BlockStatement' &&
    Array.isArray(consequent.body) &&
    consequent.body.length === 1
  ) {
    returnStatement = consequent.body[0];
  } else {
    return false;
  }

  return (
    returnStatement?.type === 'ReturnStatement' &&
    returnStatement.argument?.type === 'Identifier' &&
    returnStatement.argument.name === 'tree'
  );
}

/**
 * True for a mutant whose node is the `sourceIndex === undefined` test of an
 * `if (sourceIndex === undefined) return tree` guard. Matches whether the mutant
 * node is the IfStatement's BinaryExpression test directly (ConditionalExpression
 * mutator) or the IfStatement itself, then confirms the consequent is
 * `return tree` so only `applyReorder`'s guard shape qualifies.
 *
 * @param {import('@stryker-mutator/api/ignore').NodePath} path
 * @returns {boolean}
 */
function isApplyReorderSourceIndexGuard(path) {
  // Mutant on the binary test: parent is the IfStatement, node is its test.
  if (
    isSourceIndexUndefinedTest(path.node) &&
    path.parentPath?.isIfStatement() &&
    path.parentPath.node.test === path.node &&
    isReturnTreeConsequent(path.parentPath.node.consequent)
  ) {
    return true;
  }

  // Mutant on the IfStatement itself.
  if (
    path.isIfStatement() &&
    isSourceIndexUndefinedTest(path.node.test) &&
    isReturnTreeConsequent(path.node.consequent)
  ) {
    return true;
  }

  return false;
}

/**
 * Lifts three previously-inline `// Stryker disable` families into one central
 * ignorer (the project's stated pattern — skip centrally rather than sprinkling
 * disable comments):
 *
 *  1. TanStack `enabled`-flag arguments to usePages/useZones/useAcceptableContainers
 *     — the mocked test data ignores the `enabled` flag, so mutating the ternary
 *     argument is semantically invisible.
 *  2. `<expr>.stopPropagation()` calls — prevent event bubble to parent
 *     menus/pickers; not observable via React Testing Library.
 *  3. `applyReorder`'s `if (sourceIndex === undefined) return tree` guard —
 *     unreachable in well-formed trees (buildMaps indexes every node in nodeMap).
 *
 * @type {import('@stryker-mutator/api/ignore').Ignorer}
 */
const mutationResidueIgnorer = {
  shouldIgnore(path) {
    if (isTanStackEnabledArgument(path)) {
      return 'TanStack enabled-flag argument (mocked test data ignores enabled)';
    }
    if (isStopPropagationCall(path)) {
      return 'stopPropagation (event bubble not observable via RTL)';
    }
    if (isApplyReorderSourceIndexGuard(path)) {
      return 'applyReorder sourceIndex guard (unreachable in well-formed trees)';
    }
    return undefined;
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

/** @type {import('@stryker-mutator/api/plugin').ValuePlugin<import('@stryker-mutator/api/plugin').PluginKind.Ignore>} */
const mutationResiduePlugin = {
  kind: 'Ignore',
  name: 'mutationResidue',
  value: mutationResidueIgnorer,
};

export const strykerPlugins = [reactPlugin, i18nPlugin, mutationResiduePlugin];
