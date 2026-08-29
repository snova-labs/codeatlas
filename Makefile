# =============================================================================
# CodeAtlas — Development Commands
# =============================================================================

.DEFAULT_GOAL := help

# Colors
CYAN  := \033[36m
RESET := \033[0m

# =============================================================================
# Setup
# =============================================================================

.PHONY: install
install: ## Install all PHP and Node dependencies
	composer install
	pnpm install

.PHONY: update
update: ## Update all dependencies
	composer update
	pnpm update

# =============================================================================
# Testing
# =============================================================================

.PHONY: test
test: test-php test-frontend ## Run all tests (PHP + frontend)

.PHONY: test-php
test-php: ## Run PHP tests (Pest)
	./vendor/bin/pest

.PHONY: test-coverage
test-coverage: ## Run PHP tests with coverage (min 90%)
	./vendor/bin/pest --coverage --min=90

.PHONY: test-frontend
test-frontend: ## Run frontend tests (Vitest)
	pnpm --filter @codeatlas/web test -- --run

.PHONY: bench
bench: ## Run PHP benchmarks
	./vendor/bin/phpbench run --report=default

# =============================================================================
# Code Quality
# =============================================================================

.PHONY: lint
lint: lint-php lint-frontend ## Run all linters

.PHONY: lint-php
lint-php: ## Check PHP code style (Pint, no changes)
	./vendor/bin/pint --test

.PHONY: lint-frontend
lint-frontend: ## Check frontend code style (ESLint)
	pnpm --filter @codeatlas/web lint

.PHONY: analyze
analyze: ## Run PHPStan static analysis (level max)
	./vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: rector
rector: ## Preview Rector refactoring suggestions
	./vendor/bin/rector process --dry-run

.PHONY: format
format: format-php format-frontend ## Fix all code style

.PHONY: format-php
format-php: ## Fix PHP code style (Pint)
	./vendor/bin/pint

.PHONY: format-frontend
format-frontend: ## Fix frontend code style (Prettier + ESLint)
	pnpm --filter @codeatlas/web format

.PHONY: check
check: lint analyze test ## Full CI check (lint + analyze + test)

# =============================================================================
# Build
# =============================================================================

.PHONY: build
build: ## Build frontend for production
	pnpm build

.PHONY: dev
dev: ## Start frontend dev server
	pnpm --filter @codeatlas/web dev

# =============================================================================
# Maintenance
# =============================================================================

.PHONY: clean
clean: ## Remove all build artifacts and caches
	rm -rf vendor node_modules
	rm -rf apps/web/dist apps/web/node_modules
	rm -rf .phpunit.result.cache .phpstan
	rm -rf coverage
	find packages -name "vendor" -type d -exec rm -rf {} + 2>/dev/null || true

.PHONY: help
help: ## Show this help
	@echo ""
	@echo "  CodeAtlas — Development Commands"
	@echo "  ================================="
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-18s$(RESET) %s\n", $$1, $$2}'
	@echo ""
