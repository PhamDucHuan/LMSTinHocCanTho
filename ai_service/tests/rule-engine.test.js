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

test('Excel advanced rules verify Named Range, validation, structured table and What-If Data Table details', () => {
  const rubric = {
    criteria: [
      { id: 'name', description: 'Đặt tên vùng', max_score: 1, verification_type: 'rule', verification: { type: 'excel_named_range_exists', expected_name: 'DanhSach', expected_reference: "'DuLieu'!$A$2:$A$5", scope: 'workbook' } },
      { id: 'validation', description: 'Data Validation', max_score: 1, verification_type: 'rule', verification: { type: 'excel_data_validation', sheet: 'DuLieu', range: 'B3:B8', expected_type: 'list', formula1: '"Có,Không"' } },
      { id: 'table', description: 'Format as Table', max_score: 1, verification_type: 'rule', verification: { type: 'excel_structured_table_exists', sheet: 'DuLieu', name: 'BangDuLieu', range: 'A1:C5' } },
      { id: 'data-table', description: 'Data Table hai biến', max_score: 1, verification_type: 'rule', verification: { type: 'excel_what_if_data_table_exists', sheet: 'DuLieu', range: 'D2:E6', two_variable: true, row_input_cell: 'B1', column_input_cell: 'B2' } },
    ],
  };
  const document = {
    type: 'excel',
    sheets: [{ name: 'DuLieu', data_validations: [{ range: 'B2:B10', type: 'list', formula1: '"Có,Không"' }] }],
    named_ranges: [{ name: 'DanhSach', refers_to: "'DuLieu'!$A$2:$A$5", normalized_reference: 'A2:A5', scope: 'workbook', referenced_sheet: 'DuLieu' }],
    structured_tables: [{ sheet: 'DuLieu', name: 'BangDuLieu', display_name: 'BangDuLieu', range: 'A1:C5', style: { name: 'TableStyleMedium2' } }],
    what_if_data_tables: [{ sheet: 'DuLieu', range: 'D2:E6', two_variable: true, row_input_cell: '$B$1', column_input_cell: '$B$2' }],
  };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['passed', 'passed', 'passed', 'passed']);
});

test('Excel advanced rules reject typed values without the required structures', () => {
  const rubric = {
    criteria: [
      { id: 'name', description: 'Named Range', max_score: 1, verification_type: 'rule', verification: { type: 'excel_named_range_exists' } },
      { id: 'validation', description: 'Data Validation', max_score: 1, verification_type: 'rule', verification: { type: 'excel_data_validation' } },
      { id: 'table', description: 'Excel Table', max_score: 1, verification_type: 'rule', verification: { type: 'excel_structured_table_exists' } },
      { id: 'data-table', description: 'Data Table', max_score: 1, verification_type: 'rule', verification: { type: 'excel_data_table_exists' } },
    ],
  };
  const document = { type: 'excel', sheets: [{ name: 'Sheet1', cells: { A1: { value: 'Named Range Data Validation Data Table' } }, data_validations: [] }], named_ranges: [], structured_tables: [], what_if_data_tables: [] };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['failed', 'failed', 'failed', 'failed']);
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

test('Word advanced rules use structural TOC, SmartArt, form and table formula evidence', () => {
  const rubric = {
    criteria: [
      {
        id: 'toc', description: 'Tạo mục lục tự động 3 cấp', max_score: 1, verification_type: 'rule',
        verification: { type: 'word_toc_exists', from_level: 1, to_level: 3 },
      },
      {
        id: 'smartart', description: 'Chèn SmartArt', max_score: 1, verification_type: 'rule',
        verification: { type: 'word_smartart_exists', text_contains: 'Quy trình' },
      },
      {
        id: 'form', description: 'Tạo checkbox trong biểu mẫu', max_score: 1, verification_type: 'rule',
        verification: { type: 'word_form_control_exists', control_type: 'checkbox', title: 'Đồng ý' },
      },
      {
        id: 'formula', description: 'Tính tổng trong bảng', max_score: 1, verification_type: 'rule',
        verification: { type: 'word_table_formula', table_index: 0, row: 2, column: 3, expected_function: 'SUM' },
      },
    ],
  };
  const document = {
    type: 'word',
    table_of_contents: { automatic: true, entries: [{ heading_levels: { from: 1, to: 3 }, instruction: 'TOC \\o "1-3"' }] },
    smartart: { count: 1, diagrams: [{ text: ['Quy trình xử lý'] }] },
    form_controls: [{ kind: 'content_control', type: 'checkbox', title: 'Đồng ý điều khoản' }],
    table_formulas: [{ table_index: 0, row: 2, column: 3, formula: 'SUM(ABOVE)', result_text: '42' }],
  };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['passed', 'passed', 'passed', 'passed']);
  assert.deepEqual(results.map(item => item.score), [1, 1, 1, 1]);
});

test('Word advanced rules reject visible imitations without structural evidence', () => {
  const rubric = {
    criteria: [
      { id: 'toc', description: 'Mục lục tự động', max_score: 1, verification_type: 'rule', verification: { type: 'word_toc_exists' } },
      { id: 'smartart', description: 'SmartArt', max_score: 1, verification_type: 'rule', verification: { type: 'word_smartart_exists' } },
      { id: 'form', description: 'Form', max_score: 1, verification_type: 'rule', verification: { type: 'word_form_exists' } },
      { id: 'formula', description: 'Công thức bảng', max_score: 1, verification_type: 'rule', verification: { type: 'word_table_formula_exists' } },
    ],
  };
  const document = {
    type: 'word',
    text: 'Mục lục SmartArt Form =SUM(ABOVE)',
    table_of_contents: { automatic: false, entries: [] },
    smartart: { count: 0, diagrams: [] },
    form_controls: [],
    table_formulas: [],
  };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['failed', 'failed', 'failed', 'failed']);
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

test('PowerPoint advanced rules verify master objects, custom show order and internal slide targets', () => {
  const rubric = {
    criteria: [
      { id: 'master', description: 'Logo trên Slide Master', max_score: 1, verification_type: 'rule', verification: { type: 'ppt_master_object_exists', object_type: 'image', object_name: 'Logo' } },
      { id: 'style', description: 'Font tiêu đề Master', max_score: 1, verification_type: 'rule', verification: { type: 'ppt_master_text_style', style: 'title', level: 1, font_family: 'Arial', font_size: 32, bold: true } },
      { id: 'show', description: 'Custom Slide Show', max_score: 1, verification_type: 'rule', verification: { type: 'ppt_custom_slide_show_exists', name: 'Báo cáo nhanh', slides: [2, 1] } },
      { id: 'link', description: 'Liên kết Slide 1 đến Slide 2', max_score: 1, verification_type: 'rule', verification: { type: 'ppt_internal_hyperlink_exists', source_slide: 1, target_slide: 2, text_contains: 'chi tiết' } },
    ],
  };
  const document = {
    type: 'powerpoint',
    slide_masters: [{ file: 'master1.xml', name: 'Master Trung tâm', objects: [{ type: 'image', name: 'Logo trung tâm', text: '' }], text_styles: { title: [{ level: 1, font_family: 'Arial', font_size: 32, bold: true }] } }],
    custom_slide_shows: [{ name: 'Báo cáo nhanh', slides: [2, 1], slide_count: 2 }],
    internal_hyperlinks: [{ source_slide: 1, target_slide: 2, object_text: 'Xem chi tiết', object_name: 'Nút', trigger: 'click', link_type: 'internal_slide' }],
  };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['passed', 'passed', 'passed', 'passed']);
});

test('PowerPoint advanced rules reject visual imitations without master/show/link structures', () => {
  const rubric = {
    criteria: [
      { id: 'master', description: 'Slide Master', max_score: 1, verification_type: 'rule', verification: { type: 'ppt_slide_master_exists' } },
      { id: 'show', description: 'Custom Slide Show', max_score: 1, verification_type: 'rule', verification: { type: 'ppt_custom_slide_show_exists' } },
      { id: 'link', description: 'Hyperlink', max_score: 1, verification_type: 'rule', verification: { type: 'ppt_internal_hyperlink_exists' } },
    ],
  };
  const document = { type: 'powerpoint', slide_masters: [], custom_slide_shows: [], internal_hyperlinks: [], slides: [{ objects: [{ text: 'Slide Master Custom Slide Show Hyperlink' }] }] };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['failed', 'failed', 'failed']);
});

test('PowerPoint effect grading accepts effect presence without exact target or timing', () => {
  const rubric = {
    criteria: [
      {
        id: 'animation-present',
        description: 'Tạo hiệu ứng cho tiêu đề ở Slide 1',
        max_score: 2,
        verification_type: 'rule',
        verification: { type: 'ppt_animation_exists', slide: 1, selector: { text_contains: 'Tiêu đề khác' } },
      },
      {
        id: 'transition-present',
        description: 'Slide 2 tự chuyển sau 7 giây',
        max_score: 2,
        verification_type: 'rule',
        verification: { type: 'ppt_transition_timing', slide: 2, expected_seconds: 7 },
      },
    ],
  };
  const document = {
    type: 'powerpoint',
    slides: [
      { animations: [], animation_summary: { timing_present: true, effect_count: 0, has_effects: true }, transition: { exists: false } },
      { animations: [], animation_summary: { timing_present: false, effect_count: 0 }, transition: { exists: true, type: 'fade', advance_after_ms: null } },
    ],
  };
  const results = evaluateRuleCriteria(rubric, document);
  assert.deepEqual(results.map(item => item.status), ['passed', 'passed']);
  assert.deepEqual(results.map(item => item.score), [2, 2]);
  assert.ok(results.every(item => item.evidence.policy === 'powerpoint_effect_presence'));
});

test('color differences never reduce functional grading scores', () => {
  const cases = [
    {
      rubric: { criteria: [{ id: 'excel-color', description: 'Tô màu đỏ', max_score: 2, verification_type: 'rule', verification: { type: 'excel_fill', sheet: 'Sheet1', range: 'A1', expected: { color: 'FF0000' } } }] },
      document: { type: 'excel', sheets: [{ name: 'Sheet1', cells: { A1: { value: 'x', style: { fill: { color: '00FF00' } } } } }] },
    },
    {
      rubric: { criteria: [{ id: 'word-color', description: 'Màu chữ xanh', max_score: 2, verification_type: 'rule', verification: { type: 'word_font_color', selector: { index: 0 }, expected_value: '0000FF' } }] },
      document: { type: 'word', paragraphs: [{ text: 'Nội dung', runs: [{ text: 'Nội dung', color: 'FF0000' }] }] },
    },
    {
      rubric: { criteria: [{ id: 'ppt-color', description: 'Màu chữ vàng', max_score: 2, verification_type: 'rule', verification: { type: 'ppt_font_color', slide: 1, selector: { text_contains: 'Tiêu đề' }, expected: 'FFFF00' } }] },
      document: { type: 'powerpoint', slides: [{ objects: [{ text: 'Tiêu đề', formatting: { font_color: '000000' } }] }] },
    },
  ];
  for (const item of cases) {
    const [result] = evaluateRuleCriteria(item.rubric, item.document);
    assert.equal(result.status, 'passed');
    assert.equal(result.score, 2);
    assert.equal(result.evidence.policy, 'ignore_color_differences');
    assert.equal(result.evidence.color_warning, true);
    assert.match(result.message, /Cảnh báo/);
  }
});
