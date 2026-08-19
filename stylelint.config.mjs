export default {
  extends: ['stylelint-config-standard'],
  rules: {
    // Flat kebab-case selectors (ssgrid-block-part). BEM's __/-- separators are
    // deliberately out: parts are full flat names, modifiers live on data-/aria-
    // attributes.
    'selector-class-pattern': [
      '^[a-z][a-z0-9]*(-[a-z0-9]+)*$',
      { message: 'Expected class selector to be flat kebab-case (no __ or -- separators)' },
    ],
    // Existing code nests up to 4 levels (native CSS nesting)
    'max-nesting-depth': 4,
  },
};
