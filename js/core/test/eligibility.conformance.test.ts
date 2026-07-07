// Runs the SAME fixture file as tests/Eligibility/ConditionTest.php (Pest).
// A failure here with a passing PHP suite (or vice versa) means the two
// evaluators have diverged — fix the evaluator, never the fixture.

import { describe, expect, it } from 'vitest';
import fixtures from '../../../resources/conformance/eligibility-fixtures.json';
import type { Condition } from '../src/contract';
import { InvalidConditionError, passes } from '../src/eligibility';

type Case = {
  name: string;
  condition: Condition;
  data: Record<string, unknown>;
  expect: boolean | 'error';
};

describe('eligibility conformance fixtures', () => {
  for (const testCase of fixtures.cases as Case[]) {
    it(testCase.name, () => {
      if (testCase.expect === 'error') {
        expect(() => passes(testCase.condition, testCase.data)).toThrow(InvalidConditionError);
        return;
      }

      expect(passes(testCase.condition, testCase.data)).toBe(testCase.expect);
    });
  }
});
