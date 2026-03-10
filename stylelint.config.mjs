export default {
  extends: ['stylelint-config-standard-scss'],
  rules: {
    // BEM-like selectors: .block__element--modifier
    'selector-class-pattern': [
      '^[a-z][a-z0-9]*(-[a-z0-9]+)*(__[a-z0-9]+(-[a-z0-9]+)*)?(--[a-z0-9]+(-[a-z0-9]+)*)?$',
      { message: 'Expected class selector to follow BEM pattern' },
    ],
    // Existing code nests up to 4 levels
    'max-nesting-depth': 4,
    // Allow @use/@forward (standard-scss handles this, but be explicit)
    'scss/at-rule-no-unknown': true,
  },
};
