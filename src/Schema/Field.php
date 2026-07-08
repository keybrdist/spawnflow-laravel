<?php

namespace Spawnflow\Schema;

use BackedEnum;
use Illuminate\Support\Str;
use Spawnflow\Eligibility\Rule;

/**
 * A type-aware field descriptor.
 *
 * Describes everything both sides of the wire need to know about a field:
 * its type, widget hint, label, validation rules, enum options, relation
 * semantics, and wire format. Field descriptors are the single source of
 * truth the schema endpoint and the generator serialize from.
 */
final class Field
{
    /** @var string|array<int, mixed> */
    private string|array $rules = [];

    private ?string $label = null;

    private bool $nullable = false;

    private mixed $default = null;

    private ?string $widget = null;

    private ?string $wireFormat = null;

    private bool $writeOnly = false;

    /** @var class-string<BackedEnum>|null */
    private ?string $enumClass = null;

    /** @var list<string|int>|null Backing values when restricted via only(). */
    private ?array $onlyValues = null;

    /** @var class-string|null */
    private ?string $relatedModel = null;

    private string $displayColumn = 'name';

    private bool $searchable = false;

    private bool $multiple = false;

    private bool $scoped = true;

    /** @var list<Rule> */
    private array $eligibility = [];

    private bool $serverResolved = false;

    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
    ) {}

    // ---------------------------------------------------------------
    // Named constructors
    // ---------------------------------------------------------------

    public static function string(string $name): self
    {
        return new self($name, FieldType::String);
    }

    public static function text(string $name): self
    {
        return new self($name, FieldType::Text);
    }

    public static function int(string $name): self
    {
        return new self($name, FieldType::Integer);
    }

    public static function float(string $name): self
    {
        return new self($name, FieldType::Float);
    }

    public static function bool(string $name): self
    {
        return new self($name, FieldType::Boolean);
    }

    public static function date(string $name): self
    {
        return new self($name, FieldType::Date);
    }

    public static function datetime(string $name): self
    {
        return new self($name, FieldType::DateTime);
    }

    public static function email(string $name): self
    {
        return new self($name, FieldType::Email);
    }

    public static function password(string $name): self
    {
        $field = new self($name, FieldType::Password);
        $field->writeOnly = true;

        return $field;
    }

    public static function json(string $name): self
    {
        return new self($name, FieldType::Json);
    }

    public static function file(string $name): self
    {
        return new self($name, FieldType::File);
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function enum(string $name, string $enumClass): self
    {
        $field = new self($name, FieldType::Enum);
        $field->enumClass = $enumClass;

        return $field;
    }

    /**
     * A foreign-key field pointing at another model. Renders as a
     * (optionally searchable) select sourced from the related subject.
     *
     * @param  class-string  $modelClass
     */
    public static function belongsTo(string $name, string $modelClass): self
    {
        $field = new self($name, FieldType::Relation);
        $field->relatedModel = $modelClass;

        return $field;
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function belongsToMany(string $name, string $modelClass): self
    {
        $field = self::belongsTo($name, $modelClass);
        $field->multiple = true;

        return $field;
    }

    // ---------------------------------------------------------------
    // Fluent modifiers
    // ---------------------------------------------------------------

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Laravel validation rules for this field. Overridable per context
     * case via FieldContext::validation().
     *
     * @param  string|array<int, mixed>  $rules
     */
    public function rules(string|array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Override the widget hint derived from the field type.
     */
    public function widget(string $widget): self
    {
        $this->widget = $widget;

        return $this;
    }

    /**
     * Declare a non-standard wire format. Serialized into the schema so
     * both sides coerce from one declaration; the server applies it on
     * the write path (see coerceWire()).
     *
     * Formats: 'on_off' (booleans stored as 'on'/'off'), 'csv' (arrays
     * stored comma-joined), 'json' (arrays/objects stored JSON-encoded).
     */
    public function wire(string $format): self
    {
        $this->wireFormat = $format;

        return $this;
    }

    /**
     * Accepted on write, never present in responses (e.g. passwords).
     */
    public function writeOnly(bool $writeOnly = true): self
    {
        $this->writeOnly = $writeOnly;

        return $this;
    }

    /**
     * Restrict an enum field to a subset of cases (e.g. valid state
     * transitions for the current context).
     *
     * @param  list<BackedEnum>  $cases
     */
    public function only(array $cases): self
    {
        $this->onlyValues = array_map(fn (BackedEnum $case) => $case->value, $cases);

        return $this;
    }

    /**
     * Column on the related model shown as the option label.
     */
    public function display(string $column): self
    {
        $this->displayColumn = $column;

        return $this;
    }

    public function searchable(bool $searchable = true): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Serve options globally instead of scoping them to the caller's
     * ownership (for shared lookups like countries or plans).
     */
    public function unscoped(): self
    {
        $this->scoped = false;

        return $this;
    }

    // ---------------------------------------------------------------
    // Eligibility — intra-form data dynamics
    // ---------------------------------------------------------------
    //
    // Rules answer "given the form's current VALUES, is this field
    // visible/enabled?" — the reactive axis. WHO may touch a field in
    // WHAT record state stays in the FieldContext enum (the variant
    // axis); a field is effectively eligible only when the variant
    // grants it AND its rules pass. Conditions are restricted JSON
    // Logic (see Eligibility\Condition) referencing sibling field
    // values by name.

    /**
     * Visible only while the condition passes.
     *
     * @param  array<string, mixed>|bool  $condition
     */
    public function visibleWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::show($condition);

        return $this;
    }

    /**
     * Hidden while the condition passes.
     *
     * @param  array<string, mixed>|bool  $condition
     */
    public function hiddenWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::hide($condition);

        return $this;
    }

    /**
     * Editable only while the condition passes (rendered but disabled
     * otherwise).
     *
     * @param  array<string, mixed>|bool  $condition
     */
    public function enabledWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::enable($condition);

        return $this;
    }

    /**
     * Disabled while the condition passes.
     *
     * @param  array<string, mixed>|bool  $condition
     */
    public function disabledWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::disable($condition);

        return $this;
    }

    /**
     * Ship only the server-computed eligibility verdict — no condition
     * on the wire, no client re-evaluation. The escape hatch for rules
     * that reference fields the viewer cannot see.
     */
    public function serverResolved(bool $serverResolved = true): self
    {
        $this->serverResolved = $serverResolved;

        return $this;
    }

    // ---------------------------------------------------------------
    // Accessors
    // ---------------------------------------------------------------

    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    /** @return string|array<int, mixed> */
    public function getRules(): string|array
    {
        return $this->rules;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function getWidget(): string
    {
        return $this->widget ?? $this->type->defaultWidget();
    }

    public function getWireFormat(): ?string
    {
        return $this->wireFormat;
    }

    public function isWriteOnly(): bool
    {
        return $this->writeOnly;
    }

    /** @return class-string<BackedEnum>|null */
    public function getEnumClass(): ?string
    {
        return $this->enumClass;
    }

    /** @return class-string|null */
    public function getRelatedModel(): ?string
    {
        return $this->relatedModel;
    }

    public function getDisplayColumn(): string
    {
        return $this->displayColumn;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function isScoped(): bool
    {
        return $this->scoped;
    }

    /** @return list<Rule> */
    public function getEligibilityRules(): array
    {
        return $this->eligibility;
    }

    public function isServerResolved(): bool
    {
        return $this->serverResolved;
    }

    /**
     * Enum options as {value, label} pairs. Labels come from a label()
     * method on the enum when defined, otherwise the humanized case name.
     *
     * @return list<array{value: string|int, label: string}>
     */
    public function getOptions(): array
    {
        if ($this->enumClass === null) {
            return [];
        }

        $options = [];
        foreach ($this->enumClass::cases() as $case) {
            if ($this->onlyValues !== null && ! in_array($case->value, $this->onlyValues, true)) {
                continue;
            }
            $options[] = [
                'value' => $case->value,
                'label' => method_exists($case, 'label') ? $case->label() : Str::headline($case->name),
            ];
        }

        return $options;
    }

    /**
     * Backing values valid for this field (respects only()).
     *
     * @return list<string|int>
     */
    public function getOptionValues(): array
    {
        return array_column($this->getOptions(), 'value');
    }

    /**
     * Coerce an inbound logical value to the declared wire (storage)
     * format. Idempotent: already-wire values pass through, so payloads
     * from legacy transforms and modern boolean/array clients both land
     * on the same stored shape. No wire format → value untouched.
     */
    public function coerceWire(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->wireFormat) {
            'on_off' => match (true) {
                is_bool($value) => $value ? 'on' : 'off',
                $value === 1, $value === '1', $value === 'true' => 'on',
                $value === 0, $value === '0', $value === 'false', $value === '' => 'off',
                default => $value,
            },
            'csv' => is_array($value)
                ? implode(',', array_map(
                    fn ($element) => match (true) {
                        $element === null => '',
                        is_array($element), is_object($element) => json_encode($element),
                        default => (string) $element,
                    },
                    $value,
                ))
                : $value,
            'json' => is_array($value) || is_object($value) ? json_encode($value) : $value,
            default => $value,
        };
    }

    /**
     * Raw Laravel rules implied by the descriptor itself: the type's
     * implied rules, enum membership, relation existence, nullability.
     *
     * The single definition both the schema serializer and the server-side
     * rule resolver derive implied validation from.
     *
     * @return list<string>
     */
    public function impliedRawRules(): array
    {
        $rules = $this->type->impliedRules();

        // Wire formats change the shape validation sees: the write path
        // coerces BEFORE validating (see Flow), so a bool stored as
        // 'on'/'off' must imply membership of the stored values, not
        // `boolean`.
        if ($this->wireFormat === 'on_off') {
            $rules = array_values(array_diff($rules, ['boolean']));
            $rules[] = 'in:on,off';
        }

        if ($this->type === FieldType::Enum) {
            $rules[] = 'in:'.implode(',', $this->getOptionValues());
        }

        if ($this->type === FieldType::Relation && $this->relatedModel !== null) {
            $related = new $this->relatedModel;
            $rules[] = 'exists:'.$related->getTable().','.$related->getKeyName();
        }

        if ($this->nullable) {
            $rules[] = 'nullable';
        }

        return $rules;
    }
}
