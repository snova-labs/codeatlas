<p align="center">
  <h1 align="center">CodeAtlas</h1>
  <p align="center"><strong>Visual Architecture Explorer</strong><br>See your codebase. Understand your architecture.</p>
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-0.1.0-blue">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white">
  <img alt="Laravel" src="https://img.shields.io/badge/Laravel-11%2B-FF2D20?logo=laravel&logoColor=white">
  <img alt="License" src="https://img.shields.io/badge/license-MIT-blue">
</p>

---

CodeAtlas performs **static analysis** on a codebase and produces a **visual, interactive architecture map**. Open a project and instead of reading hundreds of files, see the whole picture: routes, controllers, services, repositories, models, events, jobs, policies — and how everything connects.

> **Laravel is the first supported framework.** The core is framework-agnostic by design.

<!-- TODO(v0.1.0): screenshot / GIF of the UI rendering a real project -->

## Quick start

```bash
# 1. Install into your Laravel app
composer require codeatlas/laravel

# 2. Analyze it
php artisan codeatlas:analyze
#    → storage/codeatlas/codeatlas-analysis.json

# 3. Open the CodeAtlas UI and drop the JSON file in
```

That's it. Routes appear as an interactive graph: what URI maps to which controller, which middleware guards it, what parameters and constraints it carries.

```bash
# Useful variations
php artisan codeatlas:scan                       # discovery dry run — what would be analyzed?
php artisan codeatlas:analyze --analyzer=routes  # run specific analyzers only
php artisan codeatlas:analyze --output=/tmp/x    # custom output directory
php artisan codeatlas:analyze --compact          # minified JSON
php artisan vendor:publish --tag=codeatlas-config
```

## What CodeAtlas is (and is not)

| | |
|---|---|
| **Is** | Static analysis · architecture visualization · read-only |
| **Is not** | A runtime debugger (Telescope) · a queue monitor (Horizon) · a profiler (Pulse) · a code-quality tool (PHPStan) |

CodeAtlas **never executes your code**, never modifies it, and never sends it anywhere. All analysis is local, AST-based (`nikic/php-parser`), and read-only.

## How it works

```
Source Code → Scanner → AST Parser → Analyzer → DTO → JSON → UI
```

Data flows one direction. JSON is the only contract between the PHP backend and the TypeScript UI — nothing else crosses the boundary.

## Packages

| Package | Role |
|---|---|
| `codeatlas/contracts` | Interfaces, enums, value objects — zero dependencies |
| `codeatlas/core` | DI container, config, events, PSR-3 logging, AST parser, plugin loader, pipeline runner |
| `codeatlas/scanner` | File discovery + classification, framework detection |
| `codeatlas/analyzer-routes` | Laravel route extraction (verbs, groups, resources, middleware, constraints) |
| `codeatlas/exporter-json` | The canonical schema document |
| `codeatlas/laravel` | ServiceProvider + artisan commands — the only Laravel-specific package |
| `@codeatlas/web` | React UI: sidebar, React Flow graph canvas, inspector |

## v0.1.0 — "First Light"

The complete vertical slice: install → analyze → visualize, for **routes**. Coming next per the [roadmap](ROADMAP.md): middleware & controller analyzers (v0.2), services & repositories (v0.3), models & database (v0.4), events & jobs (v0.5), the full dependency graph (v0.7), desktop app (v0.8), VS Code extension (v0.9).

## Development

```bash
git clone https://github.com/novaprime-code/codeatlas.git && cd codeatlas
make install     # composer + pnpm
make test        # Pest + Vitest across all packages
make lint        # Pint + PHPStan + ESLint
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow, commit conventions, and the Definition of Done. Architecture rules live in [ARCHITECTURE.md](ARCHITECTURE.md); the JSON contract in [JSON_SCHEMA.md](JSON_SCHEMA.md).

## License

MIT © [Snova Labs](https://github.com/snova-labs)
