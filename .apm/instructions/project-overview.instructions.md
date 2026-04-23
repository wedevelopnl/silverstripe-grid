---
description: Project overview, requirements, and package identity
applyTo: "**/*"
---

# Project Overview

SilverStripe Grid — a grid-based content block system for SilverStripe CMS providing structured Section > Row > Column layouts with configurable CSS framework adapters (Bootstrap, Tailwind, Bulma). **SilverStripe 6** version, ground-up rewrite on an orphaned branch.

Package: `wedevelopnl/silverstripe-grid` (type: `silverstripe-vendormodule`)

## Requirements

- PHP ^8.3
- `silverstripe/framework` ^6.0, `silverstripe/cms` ^6.0, `silverstripe/admin` ^3.0, `silverstripe/versioned` ^3.0, `silverstripe/vendor-plugin` ^3.0
- `unclecheese/display-logic` ^4.0, `wedevelopnl/silverstripe-media-field` ^6.0
- Conflicts with `dnadesign/silverstripe-elemental` (replaces its functionality)
- Optional: `silverstripe/reports` (CMS report), `tractorcow/silverstripe-fluent` (multi-locale)
