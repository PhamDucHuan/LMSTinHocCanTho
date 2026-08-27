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

test('legacy Word advanced criteria receive mixed structural rules', () => {
  const result = normalizeRubric({
    aiCriteria: '- Tạo mục lục tự động 3 cấp\n- Chèn SmartArt\n- Tạo checkbox trong biểu mẫu\n- Dùng công thức SUM(ABOVE) trong bảng',
    moduleName: 'Word',
    maxScore: 10,
  });
  assert.deepEqual(result.rubric.criteria.map(item => item.verification_type), ['mixed', 'mixed', 'mixed', 'mixed']);
  assert.deepEqual(result.rubric.criteria.map(item => item.verification.type), [
    'word_toc_exists', 'word_smartart_exists', 'word_form_control_exists', 'word_table_formula_exists',
  ]);
  assert.deepEqual(result.rubric.criteria[0].verification, { type: 'word_toc_exists', rule_weight: 0.7, from_level: 1, to_level: 3 });
  assert.equal(result.rubric.criteria[2].verification.control_type, 'checkbox');
  assert.equal(result.rubric.criteria[3].verification.expected_function, 'SUM');
});

test('legacy Excel advanced criteria receive mixed structural rules', () => {
  const result = normalizeRubric({
    aiCriteria: '- Đặt tên vùng là DanhSach\n- Tạo Data Validation dạng danh sách\n- Tạo Data Table hai biến\n- Dùng Format as Table',
    moduleName: 'Excel',
    maxScore: 10,
  });
  assert.deepEqual(result.rubric.criteria.map(item => item.verification_type), ['mixed', 'mixed', 'mixed', 'mixed']);
  assert.deepEqual(result.rubric.criteria.map(item => item.verification.type), [
    'excel_named_range_exists', 'excel_data_validation', 'excel_what_if_data_table_exists', 'excel_structured_table_exists',
  ]);
  assert.equal(result.rubric.criteria[0].verification.expected_name, 'danhsach');
  assert.equal(result.rubric.criteria[1].verification.expected_type, 'list');
  assert.equal(result.rubric.criteria[2].verification.two_variable, true);
});

test('legacy PowerPoint advanced criteria receive mixed structural rules', () => {
  const result = normalizeRubric({
    aiCriteria: '- Chèn logo trong Slide Master\n- Tạo Custom Slide Show gồm Slide 2, Slide 1\n- Tạo liên kết từ Slide 1 đến Slide 2',
    moduleName: 'PowerPoint',
    maxScore: 9,
  });
  assert.deepEqual(result.rubric.criteria.map(item => item.verification_type), ['mixed', 'mixed', 'mixed']);
  assert.deepEqual(result.rubric.criteria.map(item => item.verification.type), [
    'ppt_master_object_exists', 'ppt_custom_slide_show_exists', 'ppt_internal_hyperlink_exists',
  ]);
  assert.equal(result.rubric.criteria[0].verification.object_type, 'image');
  assert.deepEqual(result.rubric.criteria[1].verification.expected_slides, [2, 1]);
  assert.deepEqual({ source: result.rubric.criteria[2].verification.source_slide, target: result.rubric.criteria[2].verification.target_slide }, { source: 1, target: 2 });
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
