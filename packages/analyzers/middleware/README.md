# codeatlas/analyzer-middleware

Middleware analyzer for CodeAtlas. Extracts middleware classes, aliases, groups, priority, and global registration from Laravel applications — **via AST, never regex, never executing code**.

## Both registration styles supported

| Style | Source | Extracted |
|---|---|---|
| **Laravel 11+** | `bootstrap/app.php` → `->withMiddleware(fn (Middleware $m) => …)` | `alias()`, `append()`/`prepend()` (global), `appendToGroup()`/`prependToGroup()`, `web()`/`api()` shorthands, `priority()` |
| **Laravel ≤10** | `app/Http/Kernel.php` properties | `$middleware` (global), `$middlewareGroups`, `$middlewareAliases` / `$routeMiddleware`, `$middlewarePriority` |

Class discovery adds per-middleware detail from `app/Http/Middleware/*`: the FQCN, source file, and the `handle()` parameters after `$next` (the runtime parameters behind `auth:sanctum`-style usage).

## Output

- One **middleware node** per alias or discovered class. Node IDs use the alias (`middleware::auth`) — exactly what the route analyzer's `UsesMiddleware` edges target, so route and middleware graphs join with zero dangling endpoints.
- One **middleware_group node** per group (`middleware_group::web`), with `UsesMiddleware` edges to each member.
- Metadata per JSON_SCHEMA.md: `alias`, `fqcn`, `parameters`, `groups`, `priority`, `global`.

## Identity rules

| Middleware | Node ID |
|---|---|
| Aliased (`'auth' => Authenticate::class`) | `middleware::auth` |
| Registered by class only | `middleware::LogRequests` (basename) |
| Discovered on disk, never registered | `middleware::ClassName` (basename) |

Runtime parameters are never part of identity: a route using `auth:sanctum` produces an edge to `middleware::auth`; the full string stays in the route's metadata.

## Fault isolation

A broken `bootstrap/app.php` or middleware class is logged, recorded as a warning-severity `AnalysisError`, and skipped — class discovery and the rest of the run continue.

## Installation

```bash
composer require codeatlas/analyzer-middleware
```

Part of the [CodeAtlas](https://github.com/novaprime-code/codeatlas) monorepo. MIT © Snova Labs.
