<?php

namespace Spawnflow\Schema;

use Illuminate\Support\Str;
use Spawnflow\Eligibility\Rule;

/**
 * A first-class field group: a named, ordered section (or wizard step)
 * that carries the SAME eligibility envelope as a leaf field.
 *
 * Composition is AND: a hidden group hides its members regardless of
 * their own rules; a disabled group disables them. A field belongs to
 * at most one group; ungrouped fields render outside any section.
 */
final class Group
{
    private ?string $label = null;

    /** @var list<Rule> */
    private array $eligibility = [];

    private bool $serverResolved = false;

    /**
     * @param  list<string>  $fields
     */
    private function __construct(
        public readonly string $name,
        public readonly array $fields,
    ) {}

    /**
     * @param  list<string>  $fields  Member field names, in render order.
     */
    public static function make(string $name, array $fields): self
    {
        return new self($name, $fields);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** @param array<string, mixed>|bool $condition */
    public function visibleWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::show($condition);

        return $this;
    }

    /** @param array<string, mixed>|bool $condition */
    public function hiddenWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::hide($condition);

        return $this;
    }

    /** @param array<string, mixed>|bool $condition */
    public function enabledWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::enable($condition);

        return $this;
    }

    /** @param array<string, mixed>|bool $condition */
    public function disabledWhen(array|bool $condition): self
    {
        $this->eligibility[] = Rule::disable($condition);

        return $this;
    }

    /**
     * Ship only the server-computed verdict for this group — no condition
     * on the wire, no client re-evaluation.
     */
    public function serverResolved(bool $serverResolved = true): self
    {
        $this->serverResolved = $serverResolved;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->name);
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
     * The wire shape.
     *
     * @return array{name: string, label: string, fields: list<string>, eligibility?: array, serverResolved?: bool}
     */
    public function toArray(): array
    {
        $group = [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'fields' => $this->fields,
        ];

        if ($this->eligibility !== []) {
            if ($this->serverResolved) {
                $group['serverResolved'] = true;
            } else {
                $group['eligibility'] = array_map(fn (Rule $rule) => $rule->toArray(), $this->eligibility);
            }
        }

        return $group;
    }
}
