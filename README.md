# Spawnflow

**Your entire API request lifecycle in one fluent chain.**

```php
(new Flow)
    ->spawn($request)->auth()
    ->resolve('posts')
    ->ask('POST', $id)
    ->fields(PostContext::class)
    ->validate()
    ->save($request->all())
    ->present();
```

Authentication, subject resolution, ownership verification, field-level permissions, validation, and persistence — one expression that reads like a sentence.

---

## Installation

```bash
composer require spawnflow/spawnflow-laravel
```

Publish the config:

```bash
php artisan vendor:publish --tag=spawnflow-config
```

---

## Quick Start

### 1. Register subjects

Map URL segments to Eloquent models in `config/spawnflow.php`:

```php
'subjects' => [
    'posts'    => \App\Models\Post::class,
    'comments' => \App\Models\Comment::class,
],
```

### 2. Use Flow in a controller

```php
use Spawnflow\Flow;

class PostController extends Controller
{
    public function store(Request $request)
    {
        return (new Flow)
            ->spawn($request)->auth()
            ->resolve('posts')
            ->validate(['title' => 'required|string|max:255'])
            ->save($request->all())
            ->present(statusCode: 201);
    }

    public function update(Request $request, int $id)
    {
        return (new Flow)
            ->spawn($request)->auth()
            ->resolve('posts')
            ->ask('POST', $id)
            ->validate(['title' => 'required|string|max:255'])
            ->save($request->all())
            ->present();
    }

    public function destroy(Request $request, int $id)
    {
        return (new Flow)
            ->spawn($request)->auth()
            ->resolve('posts')
            ->ask('DELETE', $id)
            ->delete($id);
    }
}
```

### 3. Add routes

```php
Route::middleware('auth:api')->group(function () {
    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::post('/posts/{id}', [PostController::class, 'update']);
    Route::delete('/posts/{id}', [PostController::class, 'destroy']);
});
```

---

## Chain API

Every method returns `$this` (fluent) unless noted as terminal.

| Method | Signature | Description |
|--------|-----------|-------------|
| `spawn` | `spawn(Request $request): static` | Entry point. Extracts user and request context. |
| `auth` | `auth(?string $role = null): static` | Verifies authentication. Optionally requires a role. |
| `resolve` | `resolve(string $subject): static` | Looks up the subject alias in the registry, instantiates the model. |
| `ask` | `ask(string $method, int\|array $ids): static` | Ownership verification. Loads the instance (single ID) or validates all IDs are owned (array). |
| `fields` | `fields(?string $contextClass = null): static` | Resolves field-level permissions from a FieldContext enum. Auto-resolves from config if no class given. |
| `validate` | `validate(?array $rules = null): static` | Validates request data. Uses context rules when active, or accepts explicit rules. |
| `save` | `save(array $data): static` | Creates or updates. Strips disallowed fields when a context is active. |
| `delete` | `delete(int\|array $ids): JsonResponse` | **Terminal.** Deletes record(s) by ID. |
| `gate` | `gate(Closure $callback): static` | Arbitrary authorization. Callback receives the Flow; should throw on failure. |
| `after` | `after(Closure $callback): static` | Post-operation hook for side effects (events, jobs, notifications). |
| `present` | `present(?string $resourceClass = null, int $statusCode = 200): JsonResponse` | **Terminal.** Returns JSON response. Filters to visible fields when context is active. |
| `list` | `list(?int $perPage = null): JsonResponse` | **Terminal.** Paginated listing with ownership scoping and validated sorting. |

### Accessors

| Method | Returns |
|--------|---------|
| `getUser()` | `?User` |
| `getInstance()` | `?Model` — the loaded record (after `ask()` or `save()`) |
| `getSubject()` | `?Model` — the unhydrated model class instance |
| `getContext()` | `?FieldContext` |
| `getRequest()` | `?Request` |

---

## Field-Level Permissions

Field-level permissions use **context enums** — PHP enums that encode every role+state combination as a case. Each case declares which fields are editable, what validation rules apply, and which fields are visible in responses.

### Define a context enum

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Spawnflow\Contracts\FieldContext;

enum PostContext: string implements FieldContext
{
    case OwnerDraft     = 'owner:draft';
    case OwnerPublished = 'owner:published';
    case Viewer         = 'viewer';

    public static function resolve(User $user, Model $record): static
    {
        return match (true) {
            $user->id === $record->owner_id && $record->status === 'draft'
                => self::OwnerDraft,
            $user->id === $record->owner_id
                => self::OwnerPublished,
            default
                => self::Viewer,
        };
    }

    public function editableFields(): array
    {
        return match ($this) {
            self::OwnerDraft     => ['title', 'body', 'status'],
            self::OwnerPublished => ['title'],
            self::Viewer         => [],
        };
    }

    public function validation(): array
    {
        return match ($this) {
            self::OwnerDraft => [
                'title'  => 'required|string|max:255',
                'body'   => 'nullable|string',
                'status' => 'in:draft,published',
            ],
            self::OwnerPublished => [
                'title' => 'required|string|max:255',
            ],
            self::Viewer => [],
        };
    }

    public function visibleFields(): array
    {
        return match ($this) {
            self::OwnerDraft, self::OwnerPublished => [
                'id', 'title', 'body', 'status', 'owner_id', 'created_at', 'updated_at',
            ],
            self::Viewer => [
                'id', 'title', 'status',
            ],
        };
    }
}
```

### Register it

```php
// config/spawnflow.php
'contexts' => [
    'posts' => \App\Spawnflow\PostContext::class,
],
```

### How it works

When you call `->fields(PostContext::class)`:

1. The enum's `resolve()` inspects the user and record to pick a case (e.g., `OwnerDraft`)
2. `->validate()` uses that case's `validation()` rules
3. `->save()` strips any fields not in `editableFields()`
4. `->present()` filters the response to `visibleFields()`

If the resolved case has zero editable fields (e.g., `Viewer`), the chain throws `ForbiddenFieldAccessException` immediately.

### The discriminated union concept

Each context enum case is a **discriminated union variant**. The `value` string (e.g., `"owner:draft"`) acts as the discriminator. This maps directly to TypeScript discriminated unions for frontend type safety:

```typescript
type PostPermissions =
  | { context: 'owner:draft'; editable: { title: string; body: string; status: string } }
  | { context: 'owner:published'; editable: { title: string } }
  | { context: 'viewer'; editable: Record<string, never> };
```

---

## Generic Controller

`SpawnflowController` handles CRUD for **any** registered subject with 4 routes:

```php
use Spawnflow\SpawnflowController;

Route::middleware('auth:api')->prefix('v2')->group(function () {
    Route::get('/{subject}', [SpawnflowController::class, 'index']);
    Route::post('/{subject}', [SpawnflowController::class, 'store']);
    Route::post('/{subject}/{id}', [SpawnflowController::class, 'update']);
    Route::delete('/{subject}/{id}', [SpawnflowController::class, 'destroy']);
});
```

Adding a new resource requires **zero new controllers and zero new routes** — just a config entry and optionally a context enum.

---

## Schema Endpoint

Enable the built-in schema routes to serve field permission schemas to your frontend:

```php
// config/spawnflow.php
'schema_routes' => true,
'schema_middleware' => ['auth:api'],
```

This registers:

- `GET /spawnflow/schema/{subject}` — returns all context variants for the subject
- `GET /spawnflow/schema/{subject}/{id}` — returns the resolved variant for a specific record

**All variants response:**

```json
{
  "resource": "posts",
  "variants": [
    {
      "context": "owner:draft",
      "editable_fields": ["title", "body", "status"],
      "validation": { "title": "required|string|max:255", "body": "nullable|string", "status": "in:draft,published" },
      "visible_fields": ["id", "title", "body", "status", "owner_id", "created_at", "updated_at"]
    }
  ]
}
```

**Resolved variant response:**

```json
{
  "resource": "posts",
  "context": "owner:draft",
  "fields": {
    "title": { "editable": true, "rules": "required|string|max:255" },
    "body": { "editable": true, "rules": "nullable|string" },
    "status": { "editable": true, "rules": "in:draft,published" },
    "owner_id": { "editable": false, "rules": null }
  }
}
```

---

## Escape Hatches

Use the chain for auth and ownership, then break out for custom logic:

```php
public function stats(Request $request, int $id)
{
    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('campaigns')
        ->ask('GET', $id);

    // Break out — use accessors for custom work
    $campaign = $flow->getInstance();
    $user = $flow->getUser();

    $stats = CampaignStatsService::compute($campaign);

    return response()->json($stats);
}
```

### Available accessors

```php
$flow->getUser();      // Authenticated user
$flow->getInstance();  // Loaded record (after ask() or save())
$flow->getSubject();   // Unhydrated model (after resolve())
$flow->getContext();   // Resolved FieldContext enum case
$flow->getRequest();   // Original HTTP request
```

### Custom gates

```php
(new Flow)
    ->spawn($request)->auth()
    ->resolve('campaigns')
    ->ask('POST', $id)
    ->gate(fn ($f) => $f->getInstance()->status === 'draft'
        || throw new StateException('Cannot edit a published campaign'))
    ->save($request->all())
    ->present();
```

### Post-operation hooks

```php
->save($data)
->after(fn ($f) => CampaignCreated::dispatch($f->getInstance()))
->present();
```

---

## Configuration Reference

```php
// config/spawnflow.php
return [
    // Maps URL segment aliases to Eloquent model classes.
    'subjects' => [
        // 'posts' => \App\Models\Post::class,
    ],

    // Maps subjects to FieldContext enum classes.
    // Subjects without a context allow all $fillable fields for the owner.
    'contexts' => [
        // 'posts' => \App\Spawnflow\PostContext::class,
    ],

    // Database column linking records to their owner.
    'ownership_column' => 'ownerId',

    // Key on the User model used for ownership checks.
    'user_key' => 'id',

    // Enable GET /spawnflow/schema/{subject}/{id?} routes.
    'schema_routes' => false,

    // Middleware applied to schema routes.
    'schema_middleware' => ['auth:api'],

    // Frontend code generation settings (future).
    'generator' => [
        'output_path'  => base_path('../frontend/src/generated'),
        'type_format'  => 'typescript',
        'validation'   => 'zod',
        'emit_client'  => true,
        'emit_unions'  => true,
    ],
];
```

---

## Testing

Run the package tests:

```bash
cd packages/spawnflow
composer install
vendor/bin/pest
```

The test suite uses Orchestra Testbench with an in-memory SQLite database. All fixtures are self-contained — no application models required.

---

## License

MIT. See [LICENSE](LICENSE).
