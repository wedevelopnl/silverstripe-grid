# Fluent (Localisation) Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable optional Fluent support so each locale gets independent grid structures, with zero PHP code changes and a fully isolated Fluent test environment.

**Architecture:** `FluentIsolatedExtension` applied to `GridElement` by the implementor via project-level YAML. Fluent's ORM layer handles all query filtering and auto-locale assignment transparently. The module ships only a `suggest` in composer.json, README documentation, and Fluent integration tests running in a separate Docker service.

**Tech Stack:** SilverStripe 6, tractorcow/silverstripe-fluent, Docker Compose profiles, PHPUnit 11, Makefile

**Spec:** `docs/superpowers/specs/2026-04-03-fluent-support-design.md`

---

## File Map

### Modified files

| File | Change |
|------|--------|
| `composer.json` | Add `suggest` entry for Fluent |
| `.docker/compose.yml` | Add `app-fluent` service + `fluent` profile + `vendor-fluent` volume |
| `.docker/Dockerfile` | Add build arg for alternate composer.json path |
| `.docker/app/phpunit.xml.dist` | Add `fluent` test suite |
| `Makefile` | Add `test-fluent`, `ensure-up-fluent` targets |

### New files

| File | Purpose |
|------|---------|
| `.docker/app/composer.fluent.json` | Docker app composer.json with Fluent dependency added |
| `tests/Integration/Fluent/FluentLocaleIsolationTest.php` | Tests that elements in locale A are invisible in locale B |
| `tests/Integration/Fluent/FluentAutoScaffoldingTest.php` | Tests that auto-scaffolded children inherit the active locale |
| `tests/Integration/Fluent/FluentTreeBuilderTest.php` | Tests that tree builder returns locale-scoped trees |
| `tests/Integration/Fluent/Fixture/locales.yml` | Fixture defining two Locale records for tests |
| `docs/fluent.md` | README-style documentation for enabling Fluent support |

---

## Task 1: Composer suggest entry

**Files:**
- Modify: `composer.json:42-44`

- [ ] **Step 1: Add Fluent to suggest**

In `composer.json`, add the Fluent entry to the existing `suggest` block:

```json
"suggest": {
    "silverstripe/reports": "Enables the Grid Elements report in CMS → Reports",
    "tractorcow/silverstripe-fluent": "Required for multi-locale support with isolated element records per locale"
}
```

- [ ] **Step 2: Verify JSON is valid**

Run: `python3 -c "import json; json.load(open('composer.json'))"`
Expected: No output (valid JSON)

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "Add tractorcow/silverstripe-fluent as suggested dependency"
```

---

## Task 2: Fluent Docker environment

**Files:**
- Create: `.docker/app/composer.fluent.json`
- Modify: `.docker/Dockerfile`
- Modify: `.docker/compose.yml`

- [ ] **Step 1: Create the Fluent composer.json**

Create `.docker/app/composer.fluent.json` — identical to `.docker/app/composer.json` but with `tractorcow/silverstripe-fluent` added to `require`:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "silverstripe/recipe-cms": "^6.0",
        "tractorcow/silverstripe-fluent": "^8.0",
        "wedevelopnl/silverstripe-grid": "*"
    },
    "repositories": [
        {
            "type": "path",
            "url": "/module",
            "options": {
                "symlink": true
            }
        }
    ],
    "autoload": {
        "classmap": [
            "src/"
        ]
    },
    "require-dev": {
        "cambis/silverstan": "^2.1",
        "infection/infection": "^0.32",
        "phpstan/extension-installer": "^1.4",
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpunit/phpcov": "^10.0",
        "phpunit/phpunit": "^11.3",
        "rregeer/phpunit-coverage-check": "^0.3",
        "tomasvotruba/type-coverage": "^2.0",
        "wernerkrauss/silverstripe-rector": "^1.0"
    },
    "autoload-dev": {
        "psr-4": {
            "WeDevelop\\Grid\\Tests\\": "vendor/wedevelopnl/silverstripe-grid/tests/"
        }
    },
    "extra": {
        "project-files-installed": [
            ".htaccess",
            "app/.htaccess",
            "app/_config/mimevalidator.yml",
            "app/_config/mysite.yml",
            "app/src/Page.php",
            "app/src/PageController.php"
        ],
        "public-files-installed": [
            ".htaccess",
            "index.php",
            "web.config"
        ]
    },
    "config": {
        "allow-plugins": {
            "composer/installers": true,
            "infection/extension-installer": true,
            "phpstan/extension-installer": true,
            "silverstripe/recipe-plugin": true,
            "silverstripe/vendor-plugin": true
        }
    }
}
```

**Maintenance note:** This file must be kept in sync with `.docker/app/composer.json`. When dependencies change in the base file, replicate the change here. Add a comment at the top of both files cross-referencing each other:

```json
"_comment": "Keep in sync with composer.fluent.json (adds Fluent dependency)"
```

And in the Fluent file:

```json
"_comment": "Fluent variant of composer.json — keep in sync, only difference is tractorcow/silverstripe-fluent in require"
```

- [ ] **Step 2: Add build arg to Dockerfile**

In `.docker/Dockerfile`, replace the hardcoded composer.json COPY with a build arg:

```dockerfile
ARG COMPOSER_FILE=app/composer.json
COPY ${COMPOSER_FILE} /app/composer.json
```

This replaces line 19: `COPY app/composer.json /app/composer.json`

Verify the default value `app/composer.json` preserves existing behavior unchanged.

- [ ] **Step 3: Add Fluent service to compose.yml**

Add the `app-fluent` service and `vendor-fluent` volume to `.docker/compose.yml`. The Fluent service:
- Uses the same Dockerfile with `COMPOSER_FILE` build arg pointing to `app/composer.fluent.json`
- Shares the same `db` service (MySQL supports multiple simultaneous connections)
- Uses its own `vendor-fluent` volume (separate dependency tree)
- Does NOT expose ports (no web access needed, tests only)
- Has the same environment variables, volumes, and healthcheck as `app`
- Uses a Docker Compose profile `fluent` so it doesn't start with `docker compose up`

```yaml
  app-fluent:
    profiles:
      - fluent
    build:
      context: .
      dockerfile: Dockerfile
      args:
        COMPOSER_FILE: app/composer.fluent.json
    volumes:
      - ../composer.json:/module/composer.json:ro
      - ../src:/module/src
      - ../tests:/module/tests:ro
      - ../_config:/module/_config:ro
      - ../phpstan:/module/phpstan:ro
      - ../client/dist:/module/client/dist:ro
      - ../client/images:/module/client/images:ro
      - ../templates:/module/templates:ro
      - ../coverage:/app/coverage
      - vendor-fluent:/app/vendor
    healthcheck:
      test: test -f /tmp/.app-ready
      interval: 3s
      start_period: 60s
      retries: 20
    depends_on:
      db:
        condition: service_healthy
    environment:
      SS_DATABASE_SERVER: db
      SS_DATABASE_NAME: silverstripe_fluent
      SS_DATABASE_USERNAME: silverstripe
      SS_DATABASE_PASSWORD: silverstripe
      SS_DEFAULT_ADMIN_USERNAME: admin
      SS_DEFAULT_ADMIN_PASSWORD: admin
      SS_ENVIRONMENT_TYPE: dev
      SS_PHPUNIT_FLUSH: 1
```

Note: `SS_DATABASE_NAME: silverstripe_fluent` — a separate database so both services can run simultaneously without schema conflicts. The `db-init/grant-test-privileges.sql` already grants `ALL PRIVILEGES ON *.*`, so no additional DB init is needed.

Add `vendor-fluent` to the volumes section:

```yaml
volumes:
  vendor:
  vendor-fluent:
  db-data:
```

- [ ] **Step 4: Create the `silverstripe_fluent` database**

Add a new SQL init script `.docker/db-init/create-fluent-db.sql`:

```sql
CREATE DATABASE IF NOT EXISTS `silverstripe_fluent`;
```

The existing `grant-test-privileges.sql` already grants `ALL PRIVILEGES ON *.*` to the `silverstripe` user, so no additional grants are needed.

- [ ] **Step 5: Verify standard service still works**

Run: `make down && make up`
Expected: Standard `app` service starts, no `app-fluent` service (profile not active)

Run: `make test-unit`
Expected: Unit tests pass (unchanged behavior)

- [ ] **Step 6: Verify Fluent service builds and starts**

Run: `docker compose -f .docker/compose.yml --profile fluent up -d --build --wait`
Expected: Both `app` and `app-fluent` services start, Fluent service completes `dev/build`

Run: `docker compose -f .docker/compose.yml exec app-fluent php -r "echo class_exists('TractorCow\Fluent\Extension\FluentIsolatedExtension') ? 'Fluent OK' : 'Fluent MISSING';"`
Expected: `Fluent OK`

- [ ] **Step 7: Commit**

```bash
git add .docker/app/composer.fluent.json .docker/Dockerfile .docker/compose.yml .docker/db-init/create-fluent-db.sql
git commit -m "Add isolated Docker service for Fluent integration testing

Introduces app-fluent service behind 'fluent' profile with separate
vendor volume, database, and composer.json that includes Fluent."
```

---

## Task 3: Makefile targets and PHPUnit config

**Files:**
- Modify: `Makefile`
- Modify: `.docker/app/phpunit.xml.dist`

- [ ] **Step 1: Add `fluent` test suite and exclude from `integration` suite**

In `.docker/app/phpunit.xml.dist`, add an `<exclude>` to the `integration` suite (preventing Fluent test files from being loaded when Fluent isn't installed), and add the new `fluent` suite:

```xml
<testsuite name="integration">
    <directory>vendor/wedevelopnl/silverstripe-grid/tests/Integration</directory>
    <exclude>vendor/wedevelopnl/silverstripe-grid/tests/Integration/Fluent</exclude>
</testsuite>
```

Add the `fluent` suite after the `functional` suite:

```xml
<testsuite name="fluent">
    <directory>vendor/wedevelopnl/silverstripe-grid/tests/Integration/Fluent</directory>
</testsuite>
```

The `<exclude>` is critical: without it, `--testsuite integration` recursively scans `tests/Integration/` including the `Fluent/` subdirectory, causing class-not-found fatal errors in the standard (non-Fluent) container where `TractorCow\Fluent\*` classes don't exist.

- [ ] **Step 2: Add Makefile targets**

Add these targets to the Makefile. Place them after the existing `test-functional` target (after line 43). Update the `.PHONY` line to include the new targets.

```makefile
## Ensure Fluent services are running and ready
ensure-up-fluent: .docker/.env
	@$(COMPOSE) --profile fluent exec app-fluent true 2>/dev/null || $(COMPOSE) --profile fluent up -d --build --wait

## Run all integration + fluent tests in Fluent environment
test-fluent: ensure-up-fluent
	$(COMPOSE) exec app-fluent vendor/bin/phpunit --testsuite integration,functional,fluent
```

- [ ] **Step 3: Verify standard targets unchanged**

Run: `make test-unit`
Expected: Passes (no fluent suite loaded)

- [ ] **Step 4: Commit**

```bash
git add Makefile .docker/app/phpunit.xml.dist
git commit -m "Add Fluent test suite and make test-fluent target"
```

---

## Task 4: Fluent test fixtures and base setup

**Files:**
- Create: `tests/Integration/Fluent/Fixture/locales.yml`

- [ ] **Step 1: Create the locale fixture**

Create `tests/Integration/Fluent/Fixture/locales.yml` providing two Locale records and the standard test page:

```yaml
TractorCow\Fluent\Model\Locale:
  en:
    Locale: en_US
    Title: English
    IsGlobalDefault: 1
  nl:
    Locale: nl_NL
    Title: Dutch

SilverStripe\CMS\Model\SiteTree:
  test_page:
    Title: 'Fluent Test Page'
    URLSegment: 'fluent-test'
```

Two locales (English as default, Dutch as secondary) is the minimal setup for testing locale isolation.

- [ ] **Step 2: Commit**

```bash
git add tests/Integration/Fluent/Fixture/locales.yml
git commit -m "Add Fluent test fixture with two locales and a test page"
```

---

## Task 5: Fluent locale isolation test

**Files:**
- Create: `tests/Integration/Fluent/FluentLocaleIsolationTest.php`
- Reference: `tests/Integration/Support/GridTreeFactory.php`
- Reference: `tests/Integration/Fluent/Fixture/locales.yml`

- [ ] **Step 1: Write the test class**

Create `tests/Integration/Fluent/FluentLocaleIsolationTest.php`:

```php
<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

final class FluentLocaleIsolationTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    protected static $extra_extensions = [
        GridElement::class => [
            FluentIsolatedExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);
    }

    public function testElementInLocaleAIsInvisibleInLocaleB(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create section in English locale
        $section = GridTreeFactory::section($page, title: 'English Section');

        // Verify it exists in English
        $englishSections = Section::get()->filter('ParentID', $page->ID);
        self::assertCount(1, $englishSections);
        self::assertSame('English Section', $englishSections->first()->Title);

        // Switch to Dutch locale
        $dutchLocale = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutchLocale->Locale);

        // Same query returns no results in Dutch
        $dutchSections = Section::get()->filter('ParentID', $page->ID);
        self::assertCount(0, $dutchSections);
    }

    public function testIndependentStructuresPerLocale(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create section in English
        $enSection = GridTreeFactory::section($page, title: 'EN Section');

        // Switch to Dutch and create a different section
        $dutchLocale = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutchLocale->Locale);
        $nlSection = GridTreeFactory::section($page, title: 'NL Section');

        // Verify Dutch sees only its section
        $dutchSections = Section::get()->filter('ParentID', $page->ID);
        self::assertCount(1, $dutchSections);
        self::assertSame('NL Section', $dutchSections->first()->Title);

        // Switch back to English — sees only its section
        $englishLocale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($englishLocale->Locale);
        $englishSections = Section::get()->filter('ParentID', $page->ID);
        self::assertCount(1, $englishSections);
        self::assertSame('EN Section', $englishSections->first()->Title);
    }

    public function testAutoLocaleAssignmentOnWrite(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $englishLocale = $this->objFromFixture(Locale::class, 'en');

        $section = GridTreeFactory::section($page, title: 'Auto Locale Test');

        // Verify LocaleID was assigned automatically
        self::assertSame(
            (int) $englishLocale->ID,
            (int) $section->LocaleID,
            'LocaleID should be auto-assigned from FluentState on write'
        );
    }
}
```

- [ ] **Step 2: Run tests in Fluent environment to verify they pass**

Run: `make test-fluent`
Expected: All three tests pass. If the Fluent environment isn't ready yet, first run: `make ensure-up-fluent`

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Fluent/FluentLocaleIsolationTest.php
git commit -m "Add Fluent locale isolation integration tests

Tests that elements in one locale are invisible in another, that
independent structures can exist per locale, and that LocaleID is
auto-assigned on write by FluentIsolatedExtension."
```

---

## Task 6: Fluent auto-scaffolding test

**Files:**
- Create: `tests/Integration/Fluent/FluentAutoScaffoldingTest.php`

- [ ] **Step 1: Write the test class**

Create `tests/Integration/Fluent/FluentAutoScaffoldingTest.php`:

```php
<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

final class FluentAutoScaffoldingTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    protected static $extra_extensions = [
        GridElement::class => [
            FluentIsolatedExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);
    }

    public function testAutoScaffoldedChildrenInheritLocale(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $englishLocale = $this->objFromFixture(Locale::class, 'en');

        // Auto-scaffolding enabled (default) — creating a Section
        // triggers Row and Column creation via onAfterWrite
        $section = Section::create();
        $section->Title = 'Scaffolded Section';
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Section should have the English locale
        self::assertSame((int) $englishLocale->ID, (int) $section->LocaleID);

        // Auto-scaffolded Row should also have English locale
        $row = $section->getChildren()->first();
        self::assertInstanceOf(Row::class, $row);
        self::assertSame((int) $englishLocale->ID, (int) $row->LocaleID);

        // Auto-scaffolded Column should also have English locale
        $column = $row->getChildren()->first();
        self::assertInstanceOf(Column::class, $column);
        self::assertSame((int) $englishLocale->ID, (int) $column->LocaleID);
    }

    public function testAutoScaffoldedChildrenScopedToLocale(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create section with auto-scaffold in English
        $section = Section::create();
        $section->Title = 'EN Scaffolded';
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Switch to Dutch
        $dutchLocale = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutchLocale->Locale);

        // The English section's children should be invisible in Dutch
        // Re-fetch the section by ID — but since it's scoped to English,
        // it won't be found in Dutch context either
        $dutchSections = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => $page::class,
        ]);
        self::assertCount(0, $dutchSections, 'English-locale section should not appear in Dutch');

        // Dutch locale sees no rows or columns either
        self::assertCount(0, Row::get());
        self::assertCount(0, Column::get());
    }
}
```

- [ ] **Step 2: Run tests**

Run: `make test-fluent`
Expected: All Fluent tests pass

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Fluent/FluentAutoScaffoldingTest.php
git commit -m "Add Fluent auto-scaffolding locale inheritance tests

Verifies that auto-scaffolded Row and Column children inherit the
active locale from FluentState, and that they are invisible in
other locales."
```

---

## Task 7: Fluent tree builder test

**Files:**
- Create: `tests/Integration/Fluent/FluentTreeBuilderTest.php`

- [ ] **Step 1: Write the test class**

Create `tests/Integration/Fluent/FluentTreeBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridTreeBuilder;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

final class FluentTreeBuilderTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    protected static $extra_extensions = [
        GridElement::class => [
            FluentIsolatedExtension::class,
        ],
    ];

    private GridTreeBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);

        $this->builder = Injector::inst()->get(GridTreeBuilder::class);
    }

    public function testTreeBuilderReturnsLocaleSpecificTree(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Build English tree
        $enSection = GridTreeFactory::section($page, title: 'EN Section');
        $enRow = GridTreeFactory::row($enSection);
        GridTreeFactory::column($enRow);
        GridTreeFactory::contentElement(
            GridTreeFactory::column($enRow),
            title: 'EN Content'
        );

        // Build Dutch tree
        $dutchLocale = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutchLocale->Locale);

        $nlSection = GridTreeFactory::section($page, title: 'NL Section');
        $nlRow = GridTreeFactory::row($nlSection);
        GridTreeFactory::column($nlRow);

        // Verify Dutch tree
        $dutchTree = $this->builder->buildForPage($page, 'main');
        self::assertArrayHasKey($page->ID, $dutchTree);
        $dutchNodes = $dutchTree[$page->ID];
        self::assertCount(1, $dutchNodes);
        self::assertSame('NL Section', $dutchNodes[0]->title);

        // Switch to English and verify English tree
        $englishLocale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($englishLocale->Locale);

        $englishTree = $this->builder->buildForPage($page, 'main');
        self::assertArrayHasKey($page->ID, $englishTree);
        $englishNodes = $englishTree[$page->ID];
        self::assertCount(1, $englishNodes);
        self::assertSame('EN Section', $englishNodes[0]->title);
    }

    public function testEmptyTreeForLocaleWithNoElements(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create elements in English only
        $section = GridTreeFactory::section($page, title: 'EN Only');
        GridTreeFactory::row($section);

        // Dutch tree should be empty
        $dutchLocale = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutchLocale->Locale);

        $dutchTree = $this->builder->buildForPage($page, 'main');

        // Tree should have no sections for this page
        self::assertArrayNotHasKey($page->ID, $dutchTree);
    }
}
```

- [ ] **Step 2: Run tests**

Run: `make test-fluent`
Expected: All Fluent tests pass

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Fluent/FluentTreeBuilderTest.php
git commit -m "Add Fluent tree builder locale scoping tests

Verifies that GridTreeBuilder returns independent trees per locale
and produces empty results for locales with no elements."
```

---

## Task 8: Verify core tests pass in Fluent environment

This task has no code changes. It verifies the key architectural guarantee: core integration and functional tests pass in the Fluent environment (Fluent installed but not configured on GridElement, since no YAML ships with the module).

- [ ] **Step 1: Run core integration tests in Fluent container**

Run: `docker compose -f .docker/compose.yml exec app-fluent vendor/bin/phpunit --testsuite integration`
Expected: All existing integration tests pass

- [ ] **Step 2: Run functional tests in Fluent container**

Run: `docker compose -f .docker/compose.yml exec app-fluent vendor/bin/phpunit --testsuite functional`
Expected: All existing functional tests pass

- [ ] **Step 3: Run full Fluent test command**

Run: `make test-fluent`
Expected: Integration + functional + Fluent suites all pass

If any core tests fail, investigate — this indicates a Fluent side effect that needs to be addressed. The spec guarantees no core test breakage because the module ships no Fluent YAML config.

---

## Task 9: Documentation

**Files:**
- Create: `docs/fluent.md`

- [ ] **Step 1: Write Fluent documentation**

Create `docs/fluent.md`:

```markdown
# Fluent (Multi-Locale) Support

This module supports [tractorcow/silverstripe-fluent](https://github.com/tractorcow-farm/silverstripe-fluent) for multi-locale content. Each locale gets a fully independent grid structure — different sections, rows, columns, and content per locale.

## Requirements

- `tractorcow/silverstripe-fluent` ^8.0

## Setup

Install Fluent in your project:

```bash
composer require tractorcow/silverstripe-fluent
```

Add the following YAML configuration to your project (e.g., `app/_config/grid-fluent.yml`):

```yaml
---
Name: project-grid-fluent
Only:
  classexists: TractorCow\Fluent\Extension\FluentIsolatedExtension
---
WeDevelop\Grid\Model\GridElement:
  extensions:
    FluentIsolated: TractorCow\Fluent\Extension\FluentIsolatedExtension
```

Run `dev/build`:

```bash
vendor/bin/sake dev/build flush=1
```

## How It Works

`FluentIsolatedExtension` adds a `LocaleID` column to each grid element table, scoping every record to exactly one locale. When an author switches locale in the CMS, the grid editor automatically shows only elements belonging to that locale.

- **Query filtering**: all ORM queries are automatically filtered by the active locale
- **Auto-locale assignment**: new elements automatically inherit the active CMS locale
- **Auto-scaffolding**: creating a Section auto-creates Row and Column children in the same locale
- **Publishing**: publish cascades stay within the active locale
- **Deletion**: cascade deletes only affect same-locale children

## Important

Do **not** set `apply_isolated_locales_to_admin: false` on `GridElement`. This would cause the grid editor to display elements from all locales simultaneously, breaking the editing experience.

## Migration from Existing Data

If you enable Fluent on a site that already has grid elements, existing records will have `LocaleID = 0` and will be invisible in all locales after `dev/build`.

You must write a migration task to assign existing records to your default locale. For example:

```php
use SilverStripe\Dev\BuildTask;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Model\GridElement;

class AssignGridLocaleTask extends BuildTask
{
    public function run($request): void
    {
        $default = Locale::getDefault();

        foreach (GridElement::get()->filter('LocaleID', 0) as $element) {
            $element->LocaleID = $default->ID;
            $element->write();
        }
    }
}
```

This is your responsibility as the site developer — the correct default locale is project-specific.
```

- [ ] **Step 2: Commit**

```bash
git add docs/fluent.md
git commit -m "Add Fluent setup and migration documentation"
```

---

## Task 10: Final verification

- [ ] **Step 1: Run standard test suite**

Run: `make test`
Expected: All PHP tests pass (unit + integration + functional)

- [ ] **Step 2: Run Fluent test suite**

Run: `make test-fluent`
Expected: All tests pass (integration + functional + fluent)

- [ ] **Step 3: Run JS QA**

Run: `npm run qa`
Expected: Passes (no frontend changes)

- [ ] **Step 4: Verify no untracked files or uncommitted changes**

Run: `git status`
Expected: Clean working tree
