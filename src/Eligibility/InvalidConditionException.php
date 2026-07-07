<?php

namespace Spawnflow\Eligibility;

use RuntimeException;

/**
 * A condition could not be evaluated: unknown operator, malformed node,
 * or a var reference to a key absent from the data. Callers (Rule) catch
 * this and fail closed — the field ends up hidden/disabled, never shown
 * by accident.
 */
class InvalidConditionException extends RuntimeException {}
