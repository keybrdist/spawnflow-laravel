<?php

namespace Spawnflow\Tests\Fixtures;

/**
 * Enum whose backed values require escaping in generated string literals.
 */
enum QuotedEnum: string
{
    case Apostrophe = "it's";
    case Backslash = 'back\\slash';
    case Newline = "line\nbreak";
}
