# codeatlas/analyzer-controllers

Controller analyzer for CodeAtlas. Extracts controller classes, methods, constructor-injected dependencies, traits, interfaces, and inheritance — **via AST, never regex, never executing code**.

## What it extracts

| Feature | Detail |
|---|---|
| Identity | FQCN, short name, namespace, `abstract` and `invokable` flags |
| Inheritance | Parent class, implemented interfaces, used traits (all FQCN-resolved) |
| Methods | Name, visibility, line range; constructor excluded |
| Parameters | Name, type (use-statement resolved), nullability, pretty-printed defaults |
| Types | Scalars pass through; nullable (`?string`), union (`A|B`), and intersection (`A&B`) render to source form |
| Attributes | PHP 8 attributes per method, resolved through aliased imports |
| Dependencies | Class-typed constructor params (promoted or plain), nullable-unwrapped |

## Output

- One **controller node** per class — ID `controller::{fqcn}`, exactly what the route analyzer's `RoutesTo` edges target. With routes + middleware + controllers running together, the merged graph has **zero dangling endpoints**.
- **Extends / Implements / UsesTrait** edges for the class hierarchy.
- **DependsOn** edges (labeled with the parameter name) for dependencies whose node type is recognizable by namespace convention:

| Namespace prefix | Edge target type |
|---|---|
| `App\Services\` | `service::` |
| `App\Repositories\` | `repository::` |
| `App\Models\` | `model::` |
| anything else | metadata only, no edge |

Methods live in node **metadata** (rendered by the UI's inspector), not as separate graph nodes — method-level graph nodes arrive with the full dependency graph in v0.7.

## Fault isolation

A malformed controller is logged, recorded as a warning-severity `AnalysisError`, and skipped; sibling files are still analyzed.

## Installation

```bash
composer require codeatlas/analyzer-controllers
```

Part of the [CodeAtlas](https://github.com/novaprime-code/codeatlas) monorepo. MIT © Snova Labs.
