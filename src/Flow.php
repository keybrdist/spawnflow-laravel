<?php

namespace Spawnflow;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spawnflow\Contracts\FieldContext;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Exceptions\ForbiddenFieldAccessException;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Exceptions\UnauthenticatedException;
use Spawnflow\Validation\RuleResolver;

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
        $context = (new ContextResolver($this->registry))->resolve(
            $this->subjectAlias,
            $this->user,
            $this->instance,
            $this->request->all(),
            $contextClass,
        );

        if ($context === null) {
            return $this;
        }

        $this->context = $context;

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
     * Rule source precedence: explicit rules argument, then the subject's
     * FieldSet descriptors with the active context's validation() entries
     * overriding per field (see Validation\RuleResolver), then the
     * context's validation() alone.
     *
     * Precognition: when the request carries a `Precognition` header, the
     * chain stops here — validation runs (optionally scoped to the fields
     * named in `Precognition-Validate-Only`) and a 204 with Precognition
     * headers is returned instead of executing the rest of the chain.
     *
     * @param  array<string, string|array>|null  $rules  Explicit rules (overrides all sources).
     * @param  array<string, mixed>|null  $data  Data to validate (defaults to the request body) — for chains that unwrap or transform payloads before saving.
     */
    public function validate(?array $rules = null, ?array $data = null): static
    {
        $explicit = $rules !== null;

        $rules ??= $this->subjectAlias !== null
            ? (new RuleResolver($this->registry))->for($this->subjectAlias, $this->context)
            : ($this->context?->validation() ?? []);

        $data ??= $this->request->all();

        // Rule-ineligible fields (hidden or disabled by eligibility rules
        // for the submitted state) are never validated — their values are
        // discarded by save(), so failing them would reject discards.
        // Explicit rules are the caller's full override and stay untouched.
        if (! $explicit) {
            $rules = array_diff_key($rules, array_flip($this->ruleIneligibleFields($data)));
        }

        if ($this->request->headers->has('Precognition')) {
            $this->validatePrecognitive($rules, $data);
        }

        if ($rules !== []) {
            Validator::make($data, $rules)->validate();
        }

        return $this;
    }

    /**
     * Run a Precognition validate-only pass and halt the chain with a 204.
     *
     * Validation failures throw the standard ValidationException (422).
     * Pair with Laravel's HandlePrecognitiveRequests middleware to get
     * Precognition headers on error responses too.
     *
     * @param  array<string, string|array>  $rules
     * @param  array<string, mixed>  $data
     */
    protected function validatePrecognitive(array $rules, array $data): never
    {
        $only = $this->request->headers->get('Precognition-Validate-Only');

        if ($only !== null && $only !== '') {
            $rules = array_intersect_key($rules, array_flip(explode(',', $only)));
        }

        if ($rules !== []) {
            Validator::make($data, $rules)->validate();
        }

        throw new HttpResponseException(
            response()->noContent()->withHeaders([
                'Precognition' => 'true',
                'Precognition-Success' => 'true',
            ]),
        );
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

        // Discard values for fields whose eligibility rules make them
        // hidden or disabled in the submitted state — the contract's
        // clear-on-ineligible semantics. Rules are enforced, never
        // cosmetic: a crafted payload cannot write through a rule.
        $data = array_diff_key($data, array_flip($this->ruleIneligibleFields($data)));

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

        $this->announceChange('saved', $this->instance->getKey());

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

        $this->announceChange('deleted');

        return response()->json(null, 200);
    }

    /**
     * Announce a write: bump the subject's invalidation version (the SSE
     * channel diffs these) and dispatch the typed event for everything
     * else (Reverb broadcasting, cache busting, audit).
     */
    protected function announceChange(string $action, int|string|null $id = null): void
    {
        if ($this->subjectAlias === null) {
            return;
        }

        \Spawnflow\Support\SubjectVersion::bump($this->subjectAlias);
        event(new \Spawnflow\Events\SubjectChanged($this->subjectAlias, $id, $action));
    }

    /**
     * Fields made ineligible by their eligibility rules for the intended
     * post-write state: the loaded record's values (or field defaults on
     * create) overlaid with the submitted data.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function ruleIneligibleFields(array $data): array
    {
        $fieldSet = $this->subjectAlias !== null
            ? $this->registry->fieldsFor($this->subjectAlias)
            : null;

        if ($fieldSet === null) {
            return [];
        }

        $current = $this->instance?->attributesToArray() ?? [];

        return Eligibility::ineligible($fieldSet, array_merge($current, $data));
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
