<?php

namespace Spawnflow;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spawnflow\Contracts\FieldContext;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Exceptions\ForbiddenFieldAccessException;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Exceptions\UnauthenticatedException;

class Flow
{
    protected ?Request $request = null;

    protected ?User $user = null;

    /** The model class instance (unloaded, for queries). */
    protected ?Model $subject = null;

    /** The resolved subject alias string. */
    protected ?string $subjectAlias = null;

    /** The loaded model instance (after ask()). */
    protected ?Model $instance = null;

    /** The resolved field context. */
    protected ?FieldContext $context = null;

    protected SubjectRegistry $registry;

    public function __construct(?SubjectRegistry $registry = null)
    {
        $this->registry = $registry ?? app(SubjectRegistry::class);
    }

    // ---------------------------------------------------------------
    // Chain: Identity
    // ---------------------------------------------------------------

    /**
     * Spawn the flow context from an HTTP request.
     * Extracts the authenticated user and stores the request.
     */
    public function spawn(Request $request): static
    {
        $this->request = $request;
        $this->user = $request->user();

        return $this;
    }

    /**
     * Verify the user is authenticated.
     * Optionally check for a specific role.
     */
    public function auth(?string $role = null): static
    {
        if (! $this->user) {
            throw new UnauthenticatedException;
        }

        if ($role !== null) {
            $roles = explode(',', $this->user->roles ?? '');
            if (! in_array($role, $roles, true)) {
                throw new UnauthenticatedException("Role '$role' required");
            }
        }

        return $this;
    }

    // ---------------------------------------------------------------
    // Chain: Subject Resolution
    // ---------------------------------------------------------------

    /**
     * Resolve a subject alias to its Eloquent model.
     */
    public function resolve(string $subject): static
    {
        $this->subjectAlias = mb_strtolower($subject);
        $this->subject = $this->registry->resolve($this->subjectAlias);

        return $this;
    }

    // ---------------------------------------------------------------
    // Chain: Ownership Verification
    // ---------------------------------------------------------------

    /**
     * Verify the authenticated user owns the target record(s).
     *
     * For single IDs, loads the instance for downstream use.
     * For arrays, verifies ownership of all IDs.
     * Chainable for multi-resource checks:
     *   ->ask('POST', $groupIds)->ask('POST', $subscriberIds)
     */
    public function ask(string $method, int|array $ids): static
    {
        $ownershipColumn = config('spawnflow.ownership_column', 'ownerId');
        $userKey = config('spawnflow.user_key', 'id');

        $query = $this->subject->newQuery()
            ->where($ownershipColumn, $this->user->{$userKey});

        if (is_array($ids)) {
            $query->whereIn('id', $ids);
            $found = $query->count();
            if ($found !== count($ids)) {
                throw new OwnershipException;
            }
        } else {
            $this->instance = $query->where('id', $ids)->first();
            if (! $this->instance) {
                throw new OwnershipException;
            }
        }

        return $this;
    }

    // ---------------------------------------------------------------
    // Chain: Field-Level Permissions
    // ---------------------------------------------------------------

    /**
     * Resolve field-level permissions from a FieldContext enum.
     *
     * Determines the permission context based on the user and record state,
     * then gates downstream operations (validate, save, present) accordingly.
     *
     * On create (no instance loaded via ask()), a synthetic record is built
     * with the user's ownership so the context enum resolves correctly.
     *
     * @param  class-string<FieldContext>|null  $contextClass  Explicit context class, or null to auto-resolve from config.
     */
    public function fields(?string $contextClass = null): static
    {
        $contextClass ??= $this->registry->contextFor($this->subjectAlias);

        if ($contextClass === null) {
            return $this;
        }

        $record = $this->instance;

        // On create, no instance exists yet — build a synthetic record
        // with ownership and request data so the context enum can resolve
        // against the intended state (e.g. status = 'draft').
        if ($record === null) {
            $ownershipColumn = config('spawnflow.ownership_column', 'ownerId');
            $userKey = config('spawnflow.user_key', 'id');
            $record = $this->subject->newInstance();
            $record->forceFill($this->request->all());
            $record->{$ownershipColumn} = $this->user->{$userKey};
        }

        $this->context = $contextClass::resolve($this->user, $record);

        if ($this->context->editableFields() === []) {
            throw new ForbiddenFieldAccessException;
        }

        return $this;
    }

    // ---------------------------------------------------------------
    // Chain: Validation
    // ---------------------------------------------------------------

    /**
     * Validate request data against rules.
     *
     * If a FieldContext is active, uses its context-aware rules.
     * Otherwise, accepts explicit rules.
     *
     * @param  array<string, string|array>|null  $rules  Explicit rules (overrides context).
     */
    public function validate(?array $rules = null): static
    {
        $rules ??= $this->context?->validation() ?? [];

        if ($rules !== []) {
            Validator::make($this->request->all(), $rules)->validate();
        }

        return $this;
    }

    // ---------------------------------------------------------------
    // Chain: Persistence
    // ---------------------------------------------------------------

    /**
     * Create or update the resolved subject.
     *
     * If a FieldContext is active, strips fields not in editableFields().
     * If an instance was loaded via ask(), updates it. Otherwise creates.
     */
    public function save(array $data): static
    {
        $ownershipColumn = config('spawnflow.ownership_column', 'ownerId');
        $userKey = config('spawnflow.user_key', 'id');

        // Strip disallowed fields if a context is active
        if ($this->context) {
            $allowed = $this->context->editableFields();
            $data = array_intersect_key($data, array_flip($allowed));
        }

        if ($this->instance) {
            // Update existing record
            $this->instance->fill($data);
            $this->instance->save();
        } else {
            // Create new record
            $data[$ownershipColumn] = $this->user->{$userKey};
            $this->instance = $this->subject->newInstance();
            $this->instance->fill($data);
            $this->instance->{$ownershipColumn} = $this->user->{$userKey};
            $this->instance->save();
        }

        return $this;
    }

    /**
     * Delete record(s) by ID.
     *
     * Ownership must be verified via ask() before calling delete().
     */
    public function delete(int|array $ids): JsonResponse
    {
        $ids = is_array($ids) ? $ids : [$ids];

        $this->subject->newQuery()->whereIn('id', $ids)->delete();

        return response()->json(null, 200);
    }

    // ---------------------------------------------------------------
    // Chain: Gates & Hooks
    // ---------------------------------------------------------------

    /**
     * Arbitrary authorization gate.
     *
     * The callback receives the Flow instance. It should throw if the
     * condition is not met.
     *
     * @param  Closure(static): void  $callback
     */
    public function gate(Closure $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Post-operation hook for side effects.
     *
     * @param  Closure(static): void  $callback
     */
    public function after(Closure $callback): static
    {
        $callback($this);

        return $this;
    }

    // ---------------------------------------------------------------
    // Chain: Response
    // ---------------------------------------------------------------

    /**
     * Return a JSON response for the resolved instance.
     *
     * If a FieldContext is active, filters the response to visibleFields().
     * Optionally accepts a Laravel Resource class for custom transformation.
     *
     * @param  class-string|null  $resourceClass  A JsonResource class.
     */
    public function present(?string $resourceClass = null, int $statusCode = 200): JsonResponse
    {
        if ($resourceClass) {
            return (new $resourceClass($this->instance))->response()->setStatusCode($statusCode);
        }

        $data = $this->instance->toArray();

        if ($this->context) {
            $visible = $this->context->visibleFields();
            if ($visible !== []) {
                $data = array_intersect_key($data, array_flip($visible));
            }
        }

        return response()->json($data, $statusCode);
    }

    /**
     * Return a JSON response for a listing query.
     *
     * Applies ownership scoping and basic pagination from request params.
     */
    public function list(?int $perPage = null): JsonResponse
    {
        $ownershipColumn = config('spawnflow.ownership_column', 'ownerId');
        $userKey = config('spawnflow.user_key', 'id');

        $perPage ??= (int) ($this->request->input('per_page', 20));

        $query = $this->subject->newQuery()
            ->where($ownershipColumn, $this->user->{$userKey});

        // Sorting — validate against model columns to prevent SQL injection
        $sortBy = $this->request->input('sort_by', 'id');
        $sortDir = $this->request->input('sort_dir', 'desc');
        $allowedColumns = $this->subject->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($this->subject->getTable());
        if (! in_array($sortBy, $allowedColumns, true)) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');

        $paginator = $query->paginate($perPage);

        // Filter visible fields if context is active
        if ($this->context) {
            $visible = $this->context->visibleFields();
            if ($visible !== []) {
                $paginator->getCollection()->transform(function ($item) use ($visible) {
                    return collect($item->toArray())->only($visible)->all();
                });
            }
        }

        return response()->json($paginator);
    }

    // ---------------------------------------------------------------
    // Accessors (for last-mile / escape hatch usage)
    // ---------------------------------------------------------------

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getInstance(): ?Model
    {
        return $this->instance;
    }

    public function getSubject(): ?Model
    {
        return $this->subject;
    }

    public function getContext(): ?FieldContext
    {
        return $this->context;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }
}
