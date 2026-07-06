<?php

namespace Spawnflow\Schema;

enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'int';
    case Float = 'float';
    case Boolean = 'bool';
    case Date = 'date';
    case DateTime = 'datetime';
    case Email = 'email';
    case Password = 'password';
    case Enum = 'enum';
    case Relation = 'relation';
    case Json = 'json';
    case File = 'file';

    /**
     * Default widget hint for the frontend renderer.
     */
    public function defaultWidget(): string
    {
        return match ($this) {
            self::String, self::Email => 'input',
            self::Text => 'textarea',
            self::Integer, self::Float => 'number',
            self::Boolean => 'checkbox',
            self::Date => 'datepicker',
            self::DateTime => 'datetimepicker',
            self::Password => 'password',
            self::Enum => 'select',
            self::Relation => 'combobox',
            self::Json => 'json',
            self::File => 'file',
        };
    }

    /**
     * Validation rules implied by the type itself.
     *
     * @return string[]
     */
    public function impliedRules(): array
    {
        return match ($this) {
            self::Integer => ['integer'],
            self::Float => ['numeric'],
            self::Boolean => ['boolean'],
            self::Date, self::DateTime => ['date'],
            self::Email => ['email'],
            default => [],
        };
    }
}
