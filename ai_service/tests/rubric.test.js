import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeRubric, reconcileAiVerification, validateAiCriteriaResults } from '../lib/rubric.js';

test('rubric rejects a total different from max_score', () => {
  assert.throws(() => normalizeRubric({
    rubric: { criteria: [{ id: 'a', description: 'A', max_score: 3, verification_type: 'rule' }] },
    moduleName: 'Excel',
    maxScore: 10,
  }), /không bằng|khÃ´ng báº±ng/);
});

test('legacy criteria are converted without changing total score', () => {
  const result = normalizeRubric({ aiCriteria: "- Mục một\n- Mục hai", moduleName: 'Word', maxScore: 10 });
  assert.equal(result.rubric.criteria.length, 2);
  assert.equal(result.rubric.criteria.reduce((sum, item) => sum + item.max_score, 0), 10);
  assert.equal(result.generated_rubric.status, 'needs_review');
});

test('AI cannot add criteria or exceed criterion max score', () => {
  const criteria = [{ id: 'a', description: 'A', max_score: 2, verification_type: 'ai_review' }];
  const results = validateAiCriteriaResults({
    criteria_results: [
      { criterion_id: 'a', score: 99, status: 'passed', evidence: ['x'] },
      { criterion_id: 'invented', score: 100, status: 'passed' },
    ],
  }, criteria);
  assert.equal(results.length, 1);
  assert.equal(results[0].score, 2);
});

test('two matching AI passes confirm a criterion with high confidence', () => {
  const first = [{
    criterion_id: 'a', status: 'failed', score: 0, max_score: 2,
    evidence: ['missing title'], message: 'Missing', requires_teacher_review: false,
  }];
  const second = [{
    criterion_id: 'a', status: 'failed', score: 0, max_score: 2,
    evidence: ['title not found'], message: 'Confirmed', requires_teacher_review: false,
  }];
  const [result] = reconcileAiVerification(first, second);
  assert.equal(result.verification_outcome, 'confirmed');
  assert.equal(result.confidence, 0.9);
  assert.equal(result.requires_teacher_review, false);
  assert.equal(result.evidence.length, 2);
});

test('disagreeing AI passes avoid unfair deduction and require teacher review', () => {
  const first = [{
    criterion_id: 'a', status: 'failed', score: 0, max_score: 2,
    evidence: [], message: 'Not found', requires_teacher_review: false,
  }];
  const second = [{
    criterion_id: 'a', status: 'passed', score: 2, max_score: 2,
    evidence: ['header_text'], message: 'Found', requires_teacher_review: false,
  }];
  const [result] = reconcileAiVerification(first, second);
  assert.equal(result.status, 'passed');
  assert.equal(result.score, 2);
  assert.equal(result.verification_outcome, 'disputed');
  assert.equal(result.requires_teacher_review, true);
  assert.equal(result.confidence, 0.55);
});
