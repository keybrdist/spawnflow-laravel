<?php

namespace Spawnflow\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Spawnflow\ContextResolver;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Validation\RuleResolver;

/**
 * FormRequest bridge to the validation authority — for conventional
 * controllers that want Spawnflow's descriptor + context rules without
 * adopting the Flow chain.
 *
 * Subclass and set $subject:
 *
 *     class UpdatePostRequest extends SpawnflowFormRequest
 *     {
 *         protected string $subject = 'posts';
 *     }
 *
 * rules() resolves the caller's FieldContext (loading the record from the
 * route's {id} parameter when present) and returns the same effective rules
 * Flow::validate() enforces and the schema endpoint serves.
 *
 * authorize() stays permissive by default — ownership and field permissions
 * are the chain's (or your controller's) concern, not the request's.
 */
abstract class SpawnflowFormRequest extends FormRequest
{
    /** The registered subject alias this request validates. */
    protected string $subject;

    public function rules(): array
    {
        $registry = app(SubjectRegistry::class);

        $context = $this->user() !== null
            ? app(ContextResolver::class)->resolve(
                $this->subject,
                $this->user(),
                $this->resolveRecord($registry),
                $this->all(),
            )
            : null;

        return app(RuleResolver::class)->for($this->subject, $context);
    }

    protected function resolveRecord(SubjectRegistry $registry): ?Model
    {
        $id = $this->route('id');

        if ($id === null) {
            return null;
        }

        return $registry->resolve($this->subject)->newQuery()->find($id);
    }
}
