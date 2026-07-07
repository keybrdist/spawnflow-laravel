@php
    $editable = $field['editable'] ?? false;
    $visible = $field['visible'] ?? false;
    $widget = $field['widget'] ?? 'input';
    $disabled = ! $editable || $disabledBy($name);
    $inputStyle = 'padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:.375rem;font-size:.875rem';
@endphp

@if (($editable || $visible) && $eligible($name))
    <label data-field="{{ $name }}" style="display:grid;gap:.375rem;font-size:.875rem;font-weight:500">
        @if ($widget !== 'checkbox')
            <span>{{ $field['label'] }}</span>
        @endif

        @switch($widget)
            @case('textarea')
                <textarea wire:model.live="values.{{ $name }}" rows="4" @disabled($disabled) style="{{ $inputStyle }}"></textarea>
                @break

            @case('number')
                <input type="number" wire:model.live="values.{{ $name }}" @disabled($disabled) style="{{ $inputStyle }}" />
                @break

            @case('checkbox')
                <span style="display:flex;align-items:center;gap:.5rem">
                    <input type="checkbox" wire:model.live="values.{{ $name }}" @disabled($disabled) />
                    <span>{{ $field['label'] }}</span>
                </span>
                @break

            @case('select')
            @case('combobox')
                <select wire:model.live="values.{{ $name }}" @disabled($disabled) style="{{ $inputStyle }}">
                    <option value="">—</option>
                    @foreach ($field['options'] ?? [] as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @break

            @case('datepicker')
            @case('datetimepicker')
                <input type="{{ $widget === 'datepicker' ? 'date' : 'datetime-local' }}" wire:model.live="values.{{ $name }}" @disabled($disabled) style="{{ $inputStyle }}" />
                @break

            @default
                <input
                    type="{{ $field['type'] === 'password' ? 'password' : ($field['type'] === 'email' ? 'email' : 'text') }}"
                    wire:model.live="values.{{ $name }}"
                    @disabled($disabled)
                    style="{{ $inputStyle }}"
                />
        @endswitch

        @error('values.'.$name)
            <span style="color:#dc2626;font-weight:400">{{ $message }}</span>
        @enderror
        @error($name)
            <span style="color:#dc2626;font-weight:400">{{ $message }}</span>
        @enderror
    </label>
@endif
