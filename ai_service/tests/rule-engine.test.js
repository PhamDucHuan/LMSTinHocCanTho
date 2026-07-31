import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluateRuleCriteria } from '../lib/rule-engine.js';

test('Excel formula rule uses formula evidence rather than cached value', () => {
  const rubric = {
    criteria: [{
      id: 'formula',
      description: 'Dùng SUM',
      max_score: 2,
      verification_type: 'rule',
      verification: { type: 'excel_formula_function', sheet: 'Sheet1', range: 'C1:C2', expected_function: 'SUM' },
    }],
  };
  const document = {
    type: 'excel',
    sheets: [{
      name: 'Sheet1',
      cells: {
        C1: { address: 'C1', formula: 'SUM(A1:B1)', value: 3 },
        C2: { address: 'C2', formula: null, value: 3 },
      },
    }],
  };
  const [result] = evaluateRuleCriteria(rubric, document);
  assert.equal(result.status, 'failed');
  assert.deepEqual(result.evidence.failed_cells, ['C2']);
});

test('unsupported rule is reviewable and does not receive points', () => {
  const rubric = {
    criteria: [{
      id: 'macro',
      description: 'Kiểm tra macro',
      max_score: 1,
      verification_type: 'rule',
      verification: { type: 'excel_macro_quality' },
    }],
  };
  const [result] = evaluateRuleCriteria(rubric, { type: 'excel', sheets: [] });
  assert.equal(result.score, 0);
  assert.equal(result.requires_teacher_review, true);
});

test('Word header rule accepts equivalent content in the first-page region', () => {
  const rubric = {
    criteria: [{
      id: 'header',
      description: 'Nhập Số máy vào Header',
      max_score: 1,
      verification_type: 'rule',
      verification: { type: 'word_header_exists', text_contains: 'Số máy' },
    }],
  };
  const [result] = evaluateRuleCriteria(rubric, {
    type: 'word',
    headers: [],
    header_text: '',
    first_page_region_text: 'Số máy: 12 - Họ tên: Nguyễn Văn A',
  });
  assert.equal(result.status, 'passed');
});

test('PowerPoint rules use parsed animation and transition timing evidence', () => {
  const rubric = {
    criteria: [
      {
        id: 'animation',
        description: 'Có animation ở Slide 1',
        max_score: 1,
        verification_type: 'rule',
        verification: { type: 'ppt_animation_exists', slide: 1 },
      },
      {
        id: 'transition',
        description: 'Tự chuyển sau 3 giây',
        max_score: 1,
        verification_type: 'rule',
        verification: { type: 'ppt_transition_timing', slide: 1, expected_seconds: 3 },
      },
    ],
  };
  const document = {
    type: 'powerpoint',
    slides: [{
      animations: [{ type: 'animEffect', target_text: 'Tiêu đề', duration_ms: 500 }],
      animation_summary: { timing_present: true, effect_count: 1 },
      transition: { exists: true, type: 'fade', advance_after_ms: 3000 },
    }],
  };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['passed', 'passed']);
});
