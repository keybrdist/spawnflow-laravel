<?php

namespace Spawnflow\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spawnflow\ContextResolver;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Flow;
use Spawnflow\Schema\SchemaSerializer;

/**
 * ONE generic schema-interpreting Livewire component — the server-
 * rendered counterpart of @spawnflow/react-shadcn's <SpawnForm>. No
 * per-form components: the FieldSet + FieldContext declarations drive
 * everything, and because Livewire re-renders server-side on every
 * wire:model update, eligibility verdicts are always computed by the
 * PHP evaluator — no client evaluator, no serverResolved distinction.
 *
 *   <livewire:spawnflow-form subject="posts" :record-id="$post->id" />
 *
 * Writes go through the same Flow chain as the HTTP path (ownership,
 * variant stripping, rule enforcement, validation) — one write path,
 * two renderers.
 */
class SpawnForm extends Component
{
    public string $subject;

    public ?int $recordId = null;

    /** @var array<string, mixed> */
    public array $values = [];

    public ?string $success = null;

    public function mount(string $subject, ?int $recordId = null): void
    {
        $this->subject = mb_strtolower($subject);
        $this->recordId = $recordId;

        $registry = app(SubjectRegistry::class);
        $record = $recordId !== null
            ? $registry->resolve($this->subject)->newQuery()->findOrFail($recordId)
            : null;

        $schema = $this->schema();

        foreach ($schema['fields'] as $name => $descriptor) {
            if (! ($descriptor['editable'] ?? false)) {
                continue;
            }

            $this->values[$name] = $record?->getAttribute($name)
                ?? $descriptor['default']
                ?? ($descriptor['type'] === 'bool' ? false : null);
        }
    }

    public function save(): void
    {
        $this->success = null;

        $flow = (new Flow)
            ->spawn(request())
            ->auth()
            ->resolve($this->subject);

        if ($this->recordId !== null) {
            $flow->ask('POST', $this->recordId);
        }

        $flow->fields()
            ->validate(data: $this->values)
            ->save($this->values);

        $this->recordId ??= $flow->getInstance()->getKey();
        $this->success = 'Saved.';
    }

    public function render(): View
    {
        return view('spawnflow::spawn-form', $this->form());
    }

    /**
     * The render model: resolved schema fields joined with live rule
     * verdicts for the CURRENT values — recomputed server-side every
     * Livewire update, so eligibility reacts as the user types.
     *
     * @return array{fields: array<string, array>, groups: list<array>, verdicts: array<string, array{visible: bool, enabled: bool}>}
     */
    protected function form(): array
    {
        $registry = app(SubjectRegistry::class);
        $schema = $this->schema();

        $fieldSet = $registry->fieldsFor($this->subject);
        $verdicts = $fieldSet !== null
            ? Eligibility::fieldVerdicts($fieldSet, $this->values)
            : [];

        return [
            'fields' => $schema['fields'],
            'groups' => $schema['groups'] ?? [],
            'verdicts' => $verdicts,
        ];
    }

    /**
     * The resolved contract for the current caller — same serializer as
     * the HTTP schema endpoint, consumed in-process.
     *
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        $registry = app(SubjectRegistry::class);
        $serializer = new SchemaSerializer($registry);

        $record = $this->recordId !== null
            ? $registry->resolve($this->subject)->newQuery()->findOrFail($this->recordId)
            : null;

        $context = app(ContextResolver::class)->resolve(
            $this->subject,
            auth()->user() ?? request()->user(),
            $record,
            $this->values,
        );

        if ($context === null) {
            return $serializer->defaultSchema($this->subject);
        }

        return $serializer->resolved($this->subject, $context, $record?->attributesToArray() ?? []);
    }
}
