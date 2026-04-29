COMPOSE := docker compose -f .docker/compose.yml

.PHONY: up down destroy build test test-unit test-integration test-functional ensure-up-fluent test-fluent test-js test-e2e test-e2e-ui coverage coverage-unit coverage-integration coverage-functional coverage-js coverage-check mutate mutate-js analyse rector rector-dry qa qa-js flush dev-build _qa-analyse _qa-coverage _qa-lint _qa-format _qa-typecheck _qa-test-js _qa-build

## Generate .docker/.env with deterministic ports (auto-runs if missing)
.docker/.env:
	.docker/env.sh

## Start services (build if needed)
up: .docker/.env
	$(COMPOSE) up -d --build
	@echo "\n  Testbed running at https://localhost:$$(grep WEB_PORT .docker/.env | cut -d= -f2)\n"

## Stop services
down:
	$(COMPOSE) down

## Stop services and remove volumes
destroy:
	$(COMPOSE) down -v

## Build images without starting
build:
	$(COMPOSE) build

## Ensure services are running and ready
ensure-up:
	@$(COMPOSE) exec app true 2>/dev/null || $(COMPOSE) up -d --build --wait

## Run all tests (PHP unit + integration + functional + JS)
test: ensure-up test-unit test-integration test-functional test-js

## Run unit tests (no database or framework)
test-unit: ensure-up
	$(COMPOSE) exec app vendor/bin/phpunit --testsuite unit

## Run integration tests (full SilverStripe environment)
test-integration: ensure-up
	$(COMPOSE) exec app vendor/bin/phpunit --testsuite integration

## Run functional tests (HTTP/controller tests)
test-functional: ensure-up
	$(COMPOSE) exec app vendor/bin/phpunit --testsuite functional

## Ensure Fluent services are running and ready
ensure-up-fluent: .docker/.env
	@$(COMPOSE) --profile fluent exec app-fluent true 2>/dev/null || $(COMPOSE) --profile fluent up -d --build --wait

## Run all integration + fluent tests in Fluent environment
test-fluent: ensure-up-fluent
	$(COMPOSE) exec app-fluent vendor/bin/phpunit --testsuite integration,functional,fluent

## Run JavaScript tests (Vitest)
test-js:
	npm run test

## Run all tests with merged coverage (HTML + Clover XML)
coverage: ensure-up-fluent
	$(COMPOSE) exec app-fluent vendor/bin/phpunit \
		--coverage-html coverage/combined/html \
		--coverage-clover coverage/combined/clover.xml

## Run unit tests with coverage (individual report)
coverage-unit: ensure-up
	$(COMPOSE) exec app vendor/bin/phpunit --testsuite unit \
		--coverage-html coverage/unit/html \
		--coverage-clover coverage/unit/clover.xml

## Run integration tests with coverage (individual report)
coverage-integration: ensure-up-fluent
	$(COMPOSE) exec app-fluent vendor/bin/phpunit --testsuite integration,fluent \
		--coverage-html coverage/integration/html \
		--coverage-clover coverage/integration/clover.xml

## Run functional tests with coverage (individual report)
coverage-functional: ensure-up
	$(COMPOSE) exec app vendor/bin/phpunit --testsuite functional \
		--coverage-html coverage/functional/html \
		--coverage-clover coverage/functional/clover.xml

## Run JavaScript tests with coverage
coverage-js:
	npm run coverage

## Check PHP coverage meets minimum threshold
coverage-check: coverage
	$(COMPOSE) exec app vendor/bin/coverage-check coverage/combined/clover.xml 90

## Run PHP mutation testing (Infection) — uses Fluent container so all tests run
mutate: ensure-up-fluent
	$(COMPOSE) exec app-fluent php -d memory_limit=256M vendor/bin/infection --threads=4

## Run JavaScript mutation testing (Stryker)
mutate-js:
	npm run mutate

## Run PHPStan static analysis
analyse: ensure-up
	$(COMPOSE) exec app vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=512M

## Run Rector refactoring (applies changes)
rector: ensure-up
	$(COMPOSE) exec app vendor/bin/rector process

## Run Rector in dry-run mode (preview only)
rector-dry: ensure-up
	$(COMPOSE) exec app vendor/bin/rector process --dry-run

## Run full QA suite (all checks in parallel)
qa: ensure-up ensure-up-fluent
	$(MAKE) -j7 --output-sync=target _qa-analyse _qa-coverage _qa-lint _qa-format _qa-typecheck _qa-test-js _qa-build

## QA sub-targets (not intended to be called directly)
_qa-analyse:
	@echo "==> [analyse] running PHPStan..."
	$(COMPOSE) exec -T app vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=512M
	@echo "==> [analyse] done"

_qa-coverage:
	@echo "==> [coverage] running PHPUnit with coverage (slow, several minutes)..."
	$(COMPOSE) exec -T app-fluent vendor/bin/phpunit \
		--coverage-html coverage/combined/html \
		--coverage-clover coverage/combined/clover.xml
	$(COMPOSE) exec -T app-fluent vendor/bin/coverage-check coverage/combined/clover.xml 90
	@echo "==> [coverage] done"

_qa-lint:
	@echo "==> [lint] running Biome + Stylelint..."
	npm run lint
	@echo "==> [lint] done"

_qa-format:
	@echo "==> [format] running Biome format check..."
	npm run format:check
	@echo "==> [format] done"

_qa-typecheck:
	@echo "==> [typecheck] running tsc..."
	npm run typecheck
	@echo "==> [typecheck] done"

_qa-test-js:
	@echo "==> [test-js] running Vitest..."
	npm run test
	@echo "==> [test-js] done"

_qa-build:
	@echo "==> [build] running vite build..."
	npx vite build
	@echo "==> [build] done"

## Run E2E tests (Playwright, requires running Docker services)
test-e2e: ensure-up
	npx playwright test

## Run E2E tests with interactive UI
test-e2e-ui: ensure-up
	npx playwright test --ui

## Clear SilverStripe cache
flush: ensure-up
	$(COMPOSE) exec app vendor/bin/sake flush

## Run dev/build to rebuild the database and manifest
dev-build: ensure-up
	$(COMPOSE) exec app vendor/bin/sake dev/build flush=1

## Run JavaScript QA (lint + typecheck + test, in parallel)
qa-js:
	$(MAKE) -j5 _qa-lint _qa-format _qa-typecheck _qa-test-js _qa-build
