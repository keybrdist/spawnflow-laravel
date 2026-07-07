<?php

namespace Spawnflow\Exceptions;

use LogicException;

/**
 * An eligibility declaration is inconsistent with the schema: a rule
 * references an undeclared field, or a field the referencing field's
 * variant cannot see (the client could never re-evaluate the rule).
 * Thrown at serialization time — a developer error, loud by design.
 * Mark the field ->serverResolved() to ship the resolved boolean only.
 */
class InvalidEligibilityException extends LogicException {}
