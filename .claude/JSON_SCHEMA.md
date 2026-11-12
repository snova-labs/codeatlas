# CodeAtlas JSON Schema

> This is the contract. PHP analyzers produce it. The UI consumes it. Nothing else crosses the boundary.

## Top-Level Schema

Every analysis run produces a single JSON document:

```json
{
  "$schema": "https://codeatlas.dev/schema/v1/analysis.json",
  "version": "1.0.0",
  "project": {
    "name": "my-laravel-app",
    "path": "/Users/dev/projects/my-app",
    "framework": "laravel",
    "framework_version": "11.x",
    "php_version": "8.3.12"
  },
  "analysis": {
    "timestamp": "2026-07-05T14:30:00Z",
    "duration_ms": 1250,
    "analyzers": ["routes", "controllers", "models"]
  },
  "graph": {
    "nodes": [],
    "edges": []
  },
  "results": {
    "routes": { ... },
    "controllers": { ... },
    "models": { ... }
  },
  "errors": []
}
```

## Node Schema

Every entity in the codebase is a Node:

```json
{
  "id": "route::get::/api/users",
  "type": "route",
  "label": "GET /api/users",
  "group": "api",
  "file": {
    "path": "routes/api.php",
    "line_start": 15,
    "line_end": 15
  },
  "metadata": {},
  "tags": ["api", "authenticated"]
}
```

### Node ID Convention

Node IDs must be globally unique and deterministic:

```
{type}::{qualifier}
```

Examples:
- `route::get::/api/users`
- `controller::App\\Http\\Controllers\\UserController`
- `controller_method::App\\Http\\Controllers\\UserController::index`
- `model::App\\Models\\User`
- `service::App\\Services\\UserService`
- `event::App\\Events\\UserRegistered`
- `job::App\\Jobs\\SendWelcomeEmail`
- `middleware::auth`
- `policy::App\\Policies\\UserPolicy`
- `migration::2024_01_01_create_users_table`
- `interface::App\\Contracts\\Auditable`
- `trait::App\\Concerns\\LogsActivity`

**Referenced-only nodes.** An analyzer emits `interface::` and `trait::`
nodes for the types a class it analyzed implements or uses, even though
the declaring file is usually outside its scan scope (vendor interfaces,
`app/Concerns` traits). These nodes carry `fqcn` and
`"referenced_only": true` in metadata, a `referenced` tag, and a null
`file`. Because node IDs are deterministic and the graph is ID-keyed,
several analyzers emitting the same interface or trait collapse into one
node without coordinating.

### Node Types (enum)

```
route
controller
controller_method
interface
trait
middleware
middleware_group
service
repository
model
model_relationship
event
listener
job
notification
policy
policy_method
command
schedule_entry
migration
factory
seeder
provider
config
view
```

## Edge Schema

Every relationship between entities is an Edge:

```json
{
  "id": "edge::route::get::/api/users->controller::App\\Http\\Controllers\\UserController",
  "source": "route::get::/api/users",
  "target": "controller::App\\Http\\Controllers\\UserController",
  "type": "routes_to",
  "label": "handles",
  "metadata": {}
}
```

### Edge Types (enum)

```
routes_to          → route → controller
calls              → controller → service, service → repository
depends_on         → constructor injection dependency
extends            → class inheritance
implements         → interface implementation
uses_trait         → trait usage
uses_middleware    → route/controller → middleware
has_relationship  → model → model (hasMany, belongsTo, etc.)
dispatches        → class → event
listens_to        → listener → event
queues            → class → job
notifies          → class → notification
authorizes        → controller → policy
schedules         → scheduler → command
migrates          → migration → table
```

## Analyzer-Specific Result Schemas

### Routes Result

```json
{
  "routes": [
    {
      "id": "route::get::/api/users",
      "uri": "/api/users",
      "methods": ["GET"],
      "name": "users.index",
      "controller": "App\\Http\\Controllers\\UserController",
      "action": "index",
      "middleware": ["api", "auth:sanctum"],
      "prefix": "api",
      "domain": null,
      "where": {},
      "parameters": [],
      "file": {
        "path": "routes/api.php",
        "line_start": 15,
        "line_end": 15
      }
    }
  ]
}
```

### Controllers Result

```json
{
  "controllers": [
    {
      "id": "controller::App\\Http\\Controllers\\UserController",
      "fqcn": "App\\Http\\Controllers\\UserController",
      "name": "UserController",
      "namespace": "App\\Http\\Controllers",
      "parent": "App\\Http\\Controllers\\Controller",
      "interfaces": [],
      "traits": ["App\\Http\\Traits\\HasApiResponse"],
      "methods": [
        {
          "id": "controller_method::App\\Http\\Controllers\\UserController::index",
          "name": "index",
          "visibility": "public",
          "parameters": [
            {
              "name": "request",
              "type": "Illuminate\\Http\\Request",
              "nullable": false,
              "default": null
            }
          ],
          "return_type": "Illuminate\\Http\\JsonResponse",
          "attributes": [],
          "line_start": 25,
          "line_end": 35
        }
      ],
      "dependencies": [
        {
          "fqcn": "App\\Services\\UserService",
          "parameter": "userService",
          "type": "constructor"
        }
      ],
      "file": {
        "path": "app/Http/Controllers/UserController.php",
        "line_start": 10,
        "line_end": 80
      }
    }
  ]
}
```

### Models Result

```json
{
  "models": [
    {
      "id": "model::App\\Models\\User",
      "fqcn": "App\\Models\\User",
      "name": "User",
      "table": "users",
      "relationships": [
        {
          "id": "model_relationship::App\\Models\\User::orders",
          "name": "orders",
          "type": "hasMany",
          "related_model": "App\\Models\\Order",
          "foreign_key": "user_id",
          "local_key": "id"
        }
      ],
      "scopes": ["active", "verified"],
      "casts": {
        "email_verified_at": "datetime",
        "password": "hashed"
      },
      "fillable": ["name", "email", "password"],
      "guarded": [],
      "hidden": ["password", "remember_token"],
      "appends": ["full_name"],
      "observers": ["App\\Observers\\UserObserver"],
      "events": {
        "created": "App\\Events\\UserCreated",
        "updated": null,
        "deleted": null
      },
      "factory": "Database\\Factories\\UserFactory",
      "file": {
        "path": "app/Models/User.php",
        "line_start": 10,
        "line_end": 95
      }
    }
  ]
}
```

### Services Result

```json
{
  "services": [
    {
      "id": "service::App\\Services\\UserService",
      "fqcn": "App\\Services\\UserService",
      "name": "UserService",
      "interface": "App\\Contracts\\UserServiceInterface",
      "dependencies": [
        {
          "fqcn": "App\\Repositories\\UserRepository",
          "parameter": "userRepository",
          "type": "constructor"
        },
        {
          "fqcn": "App\\Services\\EmailService",
          "parameter": "emailService",
          "type": "constructor"
        }
      ],
      "methods": [
        {
          "name": "createUser",
          "visibility": "public",
          "return_type": "App\\Models\\User",
          "calls": [
            "App\\Repositories\\UserRepository::create",
            "App\\Services\\EmailService::sendWelcome"
          ]
        }
      ],
      "file": {
        "path": "app/Services/UserService.php",
        "line_start": 8,
        "line_end": 65
      }
    }
  ]
}
```

### Events Result

```json
{
  "events": [
    {
      "id": "event::App\\Events\\UserRegistered",
      "fqcn": "App\\Events\\UserRegistered",
      "name": "UserRegistered",
      "listeners": [
        {
          "fqcn": "App\\Listeners\\SendWelcomeEmail",
          "queued": true,
          "queue": "emails",
          "connection": "redis"
        }
      ],
      "dispatched_by": [
        "App\\Services\\UserService::createUser"
      ],
      "properties": [
        {
          "name": "user",
          "type": "App\\Models\\User"
        }
      ],
      "implements_should_broadcast": false,
      "file": {
        "path": "app/Events/UserRegistered.php",
        "line_start": 8,
        "line_end": 25
      }
    }
  ]
}
```

### Jobs Result

```json
{
  "jobs": [
    {
      "id": "job::App\\Jobs\\SendWelcomeEmail",
      "fqcn": "App\\Jobs\\SendWelcomeEmail",
      "name": "SendWelcomeEmail",
      "queue": "emails",
      "connection": "redis",
      "tries": 3,
      "timeout": 60,
      "backoff": [10, 30, 60],
      "unique": false,
      "batch": false,
      "chain": [],
      "middleware": [],
      "dispatched_by": [
        "App\\Listeners\\SendWelcomeEmailListener"
      ],
      "file": {
        "path": "app/Jobs/SendWelcomeEmail.php",
        "line_start": 10,
        "line_end": 45
      }
    }
  ]
}
```

### Middleware Result

```json
{
  "middleware": [
    {
      "id": "middleware::auth",
      "alias": "auth",
      "fqcn": "App\\Http\\Middleware\\Authenticate",
      "type": "route",
      "parameters": ["guard"],
      "groups": ["web", "api"],
      "priority": 1,
      "global": false,
      "used_by_routes": [
        "route::get::/api/users",
        "route::post::/api/users"
      ],
      "file": {
        "path": "app/Http/Middleware/Authenticate.php",
        "line_start": 8,
        "line_end": 22
      }
    }
  ]
}
```

### Policies Result

```json
{
  "policies": [
    {
      "id": "policy::App\\Policies\\UserPolicy",
      "fqcn": "App\\Policies\\UserPolicy",
      "name": "UserPolicy",
      "model": "App\\Models\\User",
      "methods": [
        {
          "name": "viewAny",
          "parameters": ["App\\Models\\User"],
          "return_type": "bool"
        },
        {
          "name": "update",
          "parameters": ["App\\Models\\User", "App\\Models\\User"],
          "return_type": "bool"
        }
      ],
      "file": {
        "path": "app/Policies/UserPolicy.php",
        "line_start": 8,
        "line_end": 55
      }
    }
  ]
}
```

### Schedule Result

```json
{
  "schedule": [
    {
      "id": "schedule::prune-expired-tokens",
      "command": "sanctum:prune-expired",
      "type": "artisan",
      "expression": "0 * * * *",
      "frequency": "hourly",
      "timezone": "UTC",
      "without_overlapping": true,
      "run_in_background": false,
      "output": "/dev/null",
      "file": {
        "path": "app/Console/Kernel.php",
        "line_start": 18,
        "line_end": 18
      }
    }
  ]
}
```

### Dependencies Result

```json
{
  "dependencies": [
    {
      "source": "controller::App\\Http\\Controllers\\UserController",
      "target": "service::App\\Services\\UserService",
      "type": "constructor_injection",
      "depth": 0
    },
    {
      "source": "service::App\\Services\\UserService",
      "target": "service::App\\Services\\EmailService",
      "type": "constructor_injection",
      "depth": 1
    },
    {
      "source": "service::App\\Services\\UserService",
      "target": "model::App\\Models\\User",
      "type": "method_call",
      "depth": 2
    }
  ]
}
```

## Errors Schema

```json
{
  "errors": [
    {
      "analyzer": "routes",
      "severity": "warning",
      "message": "Could not parse routes/channels.php",
      "file": "routes/channels.php",
      "line": null,
      "exception": "PhpParser\\Error"
    }
  ]
}
```

## Versioning

The JSON schema is versioned independently:
- `1.x.x` — stable, backward-compatible additions only
- Breaking changes → major version bump
- The `$schema` URL must always be present
- UI must check `version` and handle unknown fields gracefully
