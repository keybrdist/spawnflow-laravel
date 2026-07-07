{{-- The generic schema-interpreting form. Six widgets: input (text /
     email / password), textarea, number, checkbox, select, datepicker.
     Publish (vendor:publish --tag=spawnflow-views) to restyle. --}}
@php
    $eligible = fn (string $name) => ($verdicts[$name]['visible'] ?? true);
    $disabledBy = fn (string $name) => ($verdicts[$name]['enabled'] ?? true) === false;
    $grouped = collect($groups)->flatMap(fn ($group) => $group['fields'])->all();
@endphp

<form wire:submit="save" class="spawnflow-form" style="display:grid;gap:1rem;max-width:36rem">
    @foreach ($fields as $name => $field)
        @if (! in_array($name, $grouped, true))
            @include('spawnflow::partials.field', ['name' => $name, 'field' => $field, 'eligible' => $eligible, 'disabledBy' => $disabledBy])
        @endif
    @endforeach

    @foreach ($groups as $group)
        @php
            $members = array_filter($group['fields'], fn ($name) => isset($fields[$name]) && $eligible($name));
        @endphp
        @if ($members !== [])
            <fieldset data-group="{{ $group['name'] }}" style="display:grid;gap:1rem;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem">
                <legend style="padding:0 .25rem;font-size:.875rem;font-weight:500">{{ $group['label'] }}</legend>
                @foreach ($members as $name)
                    @include('spawnflow::partials.field', ['name' => $name, 'field' => $fields[$name], 'eligible' => $eligible, 'disabledBy' => $disabledBy])
                @endforeach
            </fieldset>
        @endif
    @endforeach

    @if ($success)
        <p style="color:#059669;font-size:.875rem" data-spawnflow-success>{{ $success }}</p>
    @endif

    <button type="submit" style="justify-self:start;padding:.5rem 1rem;border-radius:.375rem;background:#111827;color:#fff;font-size:.875rem">
        Save
    </button>
</form>
