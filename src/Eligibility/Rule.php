<?php

namespace Spawnflow\Eligibility;

/**
 * One eligibility rule: an effect plus the condition that triggers it.
 *
 * Rules reference field VALUES, never other fields' eligibility, so
 * circular visibility is structurally impossible. Evaluation errors
 * (unknown operator, bad reference) fail CLOSED — the restrictive
 * outcome (hidden/disabled) regardless of the effect's polarity.
 */
final class Rule
{
    /**
     * @param  array<string, mixed>|bool  $condition
     */
    private function __construct(
        public readonly Effect $effect,
        public readonly array|bool $condition,
    ) {}

    /** @param array<string, mixed>|bool $condition */
    public static function show(array|bool $condition): self
    {
        return new self(Effect::Show, $condition);
    }

    /** @param array<string, mixed>|bool $condition */
    public static function hide(array|bool $condition): self
    {
        return new self(Effect::Hide, $condition);
    }

    /** @param array<string, mixed>|bool $condition */
    public static function enable(array|bool $condition): self
    {
        return new self(Effect::Enable, $condition);
    }

    /** @param array<string, mixed>|bool $condition */
    public static function disable(array|bool $condition): self
    {
        return new self(Effect::Disable, $condition);
    }

    /**
     * Visibility verdict, or null when this rule governs editability.
     *
     * @param  array<string, mixed>  $data
     */
    public function visible(array $data): ?bool
    {
        if (! $this->effect->governsVisibility()) {
            return null;
        }

        return $this->outcome($data, positive: $this->effect === Effect::Show);
    }

    /**
     * Editability verdict, or null when this rule governs visibility.
     *
     * @param  array<string, mixed>  $data
     */
    public function enabled(array $data): ?bool
    {
        if ($this->effect->governsVisibility()) {
            return null;
        }

        return $this->outcome($data, positive: $this->effect === Effect::Enable);
    }

    /**
     * Field names the condition references.
     *
     * @return list<string>
     */
    public function references(): array
    {
        return Condition::references($this->condition);
    }

    /**
     * The wire shape: {effect, condition}.
     *
     * @return array{effect: string, condition: array<string, mixed>|bool}
     */
    public function toArray(): array
    {
        return [
            'effect' => $this->effect->value,
            'condition' => $this->condition,
        ];
    }

    private function outcome(array $data, bool $positive): bool
    {
        try {
            $passes = Condition::passes($this->condition, $data);
        } catch (InvalidConditionException) {
            return false;
        }

        return $positive ? $passes : ! $passes;
    }
}
