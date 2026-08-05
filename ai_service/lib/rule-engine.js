function normalizeFormula(value) {
  return String(value || '').replace(/^=/, '').replace(/\s+/g, '').replaceAll(';', ',').toUpperCase();
}

function sheetByName(document, name) {
  return document.sheets?.find(sheet => sheet.name.toLowerCase() === String(name || '').toLowerCase()) || null;
}

function rangeAddresses(range) {
  const match = String(range || '').toUpperCase().match(/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/);
  if (!match) return [String(range || '').toUpperCase()].filter(Boolean);
  const columnNumber = value => [...value].reduce((sum, char) => sum * 26 + char.charCodeAt(0) - 64, 0);
  const columnName = value => {
    let output = '';
    for (; value > 0; value = Math.floor((value - 1) / 26)) output = String.fromCharCode(((value - 1) % 26) + 65) + output;
    return output;
  };
  const addresses = [];
  for (let row = Number(match[2]); row <= Number(match[4]); row += 1) {
    for (let column = columnNumber(match[1]); column <= columnNumber(match[3]); column += 1) addresses.push(`${columnName(column)}${row}`);
  }
  return addresses;
}

function result(criterion, passed, evidence, message, unsupported = false) {
  return {
    criterion_id: criterion.id,
    description: criterion.description,
    verification_type: criterion.verification_type,
    status: unsupported ? 'unsupported' : (passed ? 'passed' : 'failed'),
    score: passed ? criterion.max_score : 0,
    max_score: criterion.max_score,
    evidence,
    message,
    requires_teacher_review: unsupported,
    confidence: unsupported ? 0.2 : 1,
  };
}

function colorAdvisoryResult(criterion, verificationType, matches, evidence = {}) {
  return result(criterion, true, {
    policy: 'ignore_color_differences',
    ignored_verification: verificationType,
    color_warning: !matches,
    ...evidence,
  }, matches
    ? 'Màu sắc đúng yêu cầu tham khảo; tiêu chí được đủ điểm.'
    : 'Cảnh báo: màu sắc khác yêu cầu tham khảo nhưng không bị trừ điểm.');
}

function selectWordParagraph(document, selector = {}) {
  if (Number.isInteger(selector.index)) return document.paragraphs?.[selector.index] || null;
  return document.paragraphs?.find(paragraph =>
    (!selector.text_contains || paragraph.text.toLowerCase().includes(String(selector.text_contains).toLowerCase()))
    && (!selector.style_name || paragraph.style_name === selector.style_name)
    && (selector.heading_level == null || String(paragraph.heading) === String(selector.heading_level))
  ) || null;
}

function evaluateExcel(criterion, document) {
  const verification = criterion.verification || {};
  const sheet = sheetByName(document, verification.sheet);
  const addresses = rangeAddresses(verification.range || verification.cell);
  const cells = addresses.map(address => sheet?.cells?.[address]).filter(Boolean);
  switch (verification.type) {
    case 'excel_sheet_exists':
    case 'excel_sheet_name':
      return result(criterion, Boolean(sheet), { expected_sheet: verification.sheet, actual_sheets: document.sheets?.map(item => item.name) || [] }, sheet ? 'Đã tìm thấy sheet.' : 'Không tìm thấy sheet yêu cầu.');
    case 'excel_cell_exists':
      return result(criterion, cells.length === 1, { cell: addresses[0], actual: cells[0] || null }, cells.length === 1 ? 'Ô tồn tại.' : 'Không tìm thấy ô.');
    case 'excel_cell_value': {
      const passed = cells.length === 1 && String(cells[0].value) === String(verification.expected);
      return result(criterion, passed, { cell: addresses[0], expected: verification.expected, actual: cells[0]?.value ?? null }, passed ? 'Giá trị ô chính xác.' : 'Giá trị ô không đúng.');
    }
    case 'excel_range_has_data': {
      const nonEmpty = cells.filter(cell => String(cell.value ?? '').trim() !== '').length;
      const passed = addresses.length > 0 && nonEmpty === addresses.length;
      return result(criterion, passed, { range: verification.range, expected_cells: addresses.length, non_empty_cells: nonEmpty }, passed ? 'Vùng dữ liệu đầy đủ.' : 'Vùng dữ liệu còn thiếu ô.');
    }
    case 'excel_formula': {
      const expected = normalizeFormula(verification.expected_formula);
      const failures = addresses.filter(address => normalizeFormula(sheet?.cells?.[address]?.formula) !== expected);
      return result(criterion, failures.length === 0 && addresses.length > 0, { range: verification.range, expected_formula: expected, failed_cells: failures, actual: Object.fromEntries(addresses.map(address => [address, sheet?.cells?.[address]?.formula ?? null])) }, failures.length ? 'Có ô không dùng đúng công thức.' : 'Công thức chính xác.');
    }
    case 'excel_formula_function': {
      const expected = String(verification.expected_function || '').toUpperCase();
      const failures = addresses.filter(address => !normalizeFormula(sheet?.cells?.[address]?.formula).startsWith(`${expected}(`));
      return result(criterion, failures.length === 0 && addresses.length > 0, { range: verification.range, expected_formula_function: expected, failed_cells: failures, actual: Object.fromEntries(addresses.map(address => [address, { formula: sheet?.cells?.[address]?.formula ?? null, value: sheet?.cells?.[address]?.value ?? null }])) }, failures.length ? 'Có ô nhập tay hoặc không dùng đúng hàm.' : `Các ô sử dụng hàm ${expected}.`);
    }
    case 'excel_formula_range': {
      const expectedRange = String(verification.expected_range || '').replace(/\s+/g, '').toUpperCase();
      const failures = addresses.filter(address => !normalizeFormula(sheet?.cells?.[address]?.formula).includes(expectedRange));
      return result(criterion, failures.length === 0 && addresses.length > 0, { expected_range: expectedRange, failed_cells: failures }, failures.length ? 'Công thức tham chiếu sai vùng.' : 'Vùng tham chiếu công thức chính xác.');
    }
    case 'excel_merge': {
      const passed = Boolean(sheet?.merged_cells?.map(value => value.toUpperCase()).includes(String(verification.range || '').toUpperCase()));
      return result(criterion, passed, { expected_merge: verification.range, actual_merges: sheet?.merged_cells || [] }, passed ? 'Merge chính xác.' : 'Thiếu vùng merge yêu cầu.');
    }
    case 'excel_font':
    case 'excel_border':
    case 'excel_alignment':
    case 'excel_number_format': {
      const property = verification.type.replace('excel_', '');
      const expected = verification.expected || {};
      const failures = addresses.filter(address => {
        const actual = property === 'number_format' ? sheet?.cells?.[address]?.number_format : sheet?.cells?.[address]?.style?.[property];
        return Object.entries(typeof expected === 'object' ? expected : { value: expected }).some(([key, value]) => String(key === 'value' ? actual : actual?.[key]).toLowerCase() !== String(value).toLowerCase());
      });
      return result(criterion, failures.length === 0 && addresses.length > 0, { range: verification.range, expected, failed_cells: failures }, failures.length ? `Định dạng ${property} chưa đúng.` : `Định dạng ${property} chính xác.`);
    }
    case 'excel_fill': {
      const expected = verification.expected || {};
      const failures = addresses.filter(address => {
        const actual = sheet?.cells?.[address]?.style?.fill;
        return Object.entries(typeof expected === 'object' ? expected : { value: expected }).some(([key, value]) => String(key === 'value' ? actual : actual?.[key]).toLowerCase() !== String(value).toLowerCase());
      });
      return colorAdvisoryResult(criterion, verification.type, failures.length === 0 && addresses.length > 0, { range: verification.range, expected, failed_cells: failures });
    }
    case 'excel_row_height': {
      const row = sheet?.rows?.find(item => item.index === Number(verification.row));
      const passed = row && Math.abs(Number(row.height) - Number(verification.expected)) <= Number(verification.tolerance || 0);
      return result(criterion, passed, { row: verification.row, expected: verification.expected, actual: row?.height ?? null }, passed ? 'Chiều cao hàng chính xác.' : 'Chiều cao hàng chưa đúng.');
    }
    case 'excel_column_width': {
      const column = sheet?.columns?.find(item => Number(verification.column) >= item.min && Number(verification.column) <= item.max);
      const passed = column && Math.abs(Number(column.width) - Number(verification.expected)) <= Number(verification.tolerance || 0);
      return result(criterion, passed, { column: verification.column, expected: verification.expected, actual: column?.width ?? null }, passed ? 'Độ rộng cột chính xác.' : 'Độ rộng cột chưa đúng.');
    }
    case 'excel_chart_exists': return result(criterion, (sheet?.chart_count || 0) > 0, { chart_count: sheet?.chart_count || 0 }, (sheet?.chart_count || 0) ? 'Có biểu đồ.' : 'Không tìm thấy biểu đồ.');
    case 'excel_image_exists': return result(criterion, (sheet?.image_count || 0) > 0, { image_count: sheet?.image_count || 0 }, (sheet?.image_count || 0) ? 'Có hình ảnh.' : 'Không tìm thấy hình ảnh.');
    case 'excel_freeze_pane': return result(criterion, Boolean(sheet?.freeze_pane), { actual: sheet?.freeze_pane }, sheet?.freeze_pane ? 'Đã freeze pane.' : 'Chưa freeze pane.');
    case 'excel_data_validation': return result(criterion, (sheet?.data_validations?.length || 0) > 0, { validations: sheet?.data_validations || [] }, (sheet?.data_validations?.length || 0) ? 'Có data validation.' : 'Không tìm thấy data validation.');
    case 'excel_auto_filter': return result(criterion, Boolean(sheet?.auto_filter), { actual: sheet?.auto_filter }, sheet?.auto_filter ? 'Có auto filter.' : 'Không tìm thấy auto filter.');
    default: return result(criterion, false, { rule: verification.type }, 'Rule Excel chưa được hỗ trợ.', true);
  }
}

function evaluateWord(criterion, document) {
  const verification = criterion.verification || {};
  const paragraph = selectWordParagraph(document, verification.selector);
  const runs = paragraph?.runs || [];
  const expected = verification.expected || {};
  const firstRun = runs.find(run => run.text.trim()) || {};
  const actual = { style_name: paragraph?.style_name, alignment: paragraph?.alignment, font_family: firstRun.font_family, font_size: firstRun.font_size, bold: firstRun.bold, italic: firstRun.italic, underline: firstRun.underline, font_color: firstRun.color };
  const formatRule = {
    word_style_name: 'style_name', word_font_family: 'font_family', word_font_size: 'font_size', word_bold: 'bold',
    word_italic: 'italic', word_underline: 'underline', word_font_color: 'font_color', word_alignment: 'alignment',
  }[verification.type];
  if (verification.type === 'word_font_color') {
    const expectedValue = expected.font_color ?? verification.expected_value;
    const matches = Boolean(paragraph) && String(actual.font_color).toLowerCase() === String(expectedValue).toLowerCase();
    return colorAdvisoryResult(criterion, verification.type, matches, { selector: verification.selector, expected: expectedValue, actual: actual.font_color ?? null });
  }
  if (formatRule) {
    const expectedValue = expected[formatRule] ?? verification.expected_value;
    const passed = paragraph && String(actual[formatRule]).toLowerCase() === String(expectedValue).toLowerCase();
    return result(criterion, passed, { selector: verification.selector, expected: expectedValue, actual: actual[formatRule] ?? null }, passed ? 'Định dạng đoạn chính xác.' : 'Định dạng đoạn chưa đúng.');
  }
  switch (verification.type) {
    case 'word_text_exists': {
      const passed = document.text?.toLowerCase().includes(String(verification.text || '').toLowerCase());
      return result(criterion, passed, { expected_text: verification.text }, passed ? 'Có nội dung yêu cầu.' : 'Thiếu nội dung yêu cầu.');
    }
    case 'word_heading_exists': return result(criterion, Boolean(paragraph), { selector: verification.selector }, paragraph ? 'Có heading yêu cầu.' : 'Không tìm thấy heading.');
    case 'word_table_exists': return result(criterion, (document.tables?.length || 0) > 0, { table_count: document.tables?.length || 0 }, document.tables?.length ? 'Có bảng.' : 'Không tìm thấy bảng.');
    case 'word_table_dimensions': {
      const table = document.tables?.[Number(verification.table_index || 0)];
      const passed = table?.rows === Number(verification.rows) && table?.columns === Number(verification.columns);
      return result(criterion, passed, { expected: { rows: verification.rows, columns: verification.columns }, actual: table || null }, passed ? 'Kích thước bảng chính xác.' : 'Kích thước bảng chưa đúng.');
    }
    case 'word_header_exists': {
      const expectedText = String(verification.text || verification.text_contains || '').toLowerCase();
      const headerText = String(document.header_text || '').toLowerCase();
      const firstPageText = String(document.first_page_region_text || '').toLowerCase();
      const hasHeader = document.headers?.some(value => value.trim());
      const passed = expectedText
        ? headerText.includes(expectedText) || firstPageText.includes(expectedText)
        : Boolean(hasHeader);
      return result(criterion, passed, {
        expected_text: expectedText || null,
        header_text: document.header_text || '',
        first_page_region_text: document.first_page_region_text || '',
      }, passed ? 'Đã tìm thấy nội dung Header hoặc nội dung tương đương ở đầu trang.' : 'Chưa tìm thấy nội dung Header yêu cầu.');
    }
    case 'word_footer_exists': return result(criterion, document.footers?.some(value => value.trim()), { footers: document.footers || [] }, document.footers?.some(value => value.trim()) ? 'Có footer.' : 'Thiếu footer.');
    case 'word_page_orientation': return result(criterion, document.page_settings?.orientation === verification.expected, { expected: verification.expected, actual: document.page_settings?.orientation }, 'Đã kiểm tra hướng trang.');
    case 'word_image_exists': return result(criterion, (document.image_count || 0) > 0, { image_count: document.image_count || 0 }, document.image_count ? 'Có hình ảnh.' : 'Thiếu hình ảnh.');
    default: return result(criterion, false, { rule: verification.type }, 'Rule Word chưa được hỗ trợ đầy đủ hoặc thuộc tính parser không đọc được.', true);
  }
}

function evaluatePowerPoint(criterion, document) {
  const verification = criterion.verification || {};
  const slide = document.slides?.[Number(verification.slide || 1) - 1];
  const object = slide?.objects?.find(item => !verification.selector?.text_contains || item.text.toLowerCase().includes(String(verification.selector.text_contains).toLowerCase()));
  switch (verification.type) {
    case 'ppt_slide_count': return result(criterion, document.slides?.length === Number(verification.expected), { expected: verification.expected, actual: document.slides?.length || 0 }, 'Đã kiểm tra số slide.');
    case 'ppt_title_exists': return result(criterion, Boolean(slide?.title), { slide: verification.slide, actual: slide?.title }, slide?.title ? 'Có title.' : 'Thiếu title.');
    case 'ppt_text_exists': return result(criterion, Boolean(object), { slide: verification.slide, expected: verification.selector?.text_contains }, object ? 'Có nội dung yêu cầu.' : 'Thiếu nội dung yêu cầu.');
    case 'ppt_image_exists':
    case 'ppt_chart_exists':
    case 'ppt_table_exists': {
      const type = verification.type.replace('ppt_', '').replace('_exists', '');
      const count = slide?.objects?.filter(item => item.type === type).length || 0;
      return result(criterion, count > 0, { slide: verification.slide, count }, count ? `Có ${type}.` : `Thiếu ${type}.`);
    }
    case 'ppt_font_family':
    case 'ppt_font_size':
    case 'ppt_bold': {
      const property = verification.type.replace('ppt_', '');
      const actual = object?.formatting?.[property];
      const passed = object && String(actual).toLowerCase() === String(verification.expected).toLowerCase();
      return result(criterion, passed, { expected: verification.expected, actual: actual ?? null }, passed ? 'Định dạng chính xác.' : 'Định dạng chưa đúng.');
    }
    case 'ppt_font_color': {
      const actual = object?.formatting?.font_color;
      const matches = Boolean(object) && String(actual).toLowerCase() === String(verification.expected).toLowerCase();
      return colorAdvisoryResult(criterion, verification.type, matches, { expected: verification.expected, actual: actual ?? null });
    }
    case 'ppt_object_position':
    case 'ppt_object_size': {
      if (!object) return result(criterion, false, { selector: verification.selector }, 'Không tìm thấy đối tượng.');
      const keys = verification.type === 'ppt_object_position' ? ['x', 'y'] : ['width', 'height'];
      const tolerance = Number(verification.tolerance || 0);
      const passed = keys.every(key => Math.abs(Number(object.position[key]) - Number(verification.expected?.[key])) <= tolerance);
      return result(criterion, passed, { expected: verification.expected, actual: object.position, tolerance }, passed ? 'Vị trí/kích thước trong sai số cho phép.' : 'Vị trí/kích thước ngoài sai số.');
    }
    case 'ppt_notes_exists': return result(criterion, Boolean(slide?.notes), { slide: verification.slide }, slide?.notes ? 'Có speaker notes.' : 'Thiếu speaker notes.');
    case 'ppt_animation_exists': {
      const animations = slide?.animations || [];
      const targetText = String(verification.selector?.text_contains || '').toLowerCase();
      const matching = targetText ? animations.filter(item => item.target_text.toLowerCase().includes(targetText)) : animations;
      return result(criterion, matching.length > 0, {
        slide: verification.slide,
        target_text: targetText || null,
        animations: matching,
        animation_summary: slide?.animation_summary || null,
      }, matching.length ? 'Đã đọc được hiệu ứng chuyển động của đối tượng.' : 'Không tìm thấy hiệu ứng chuyển động yêu cầu.');
    }
    case 'ppt_transition_exists':
      return result(criterion, Boolean(slide?.transition?.exists), { slide: verification.slide, transition: slide?.transition || null }, slide?.transition?.exists ? 'Đã đọc được hiệu ứng chuyển slide.' : 'Không có hiệu ứng chuyển slide.');
    case 'ppt_transition_timing': {
      const expectedMs = Number(verification.expected_ms ?? Number(verification.expected_seconds || 0) * 1000);
      const actualMs = slide?.transition?.advance_after_ms;
      const tolerance = Number(verification.tolerance_ms || 250);
      const passed = Number.isFinite(expectedMs) && actualMs != null && Math.abs(Number(actualMs) - expectedMs) <= tolerance;
      return result(criterion, passed, { slide: verification.slide, expected_ms: expectedMs, actual_ms: actualMs, transition: slide?.transition || null }, passed ? 'Thời gian tự chuyển slide đúng.' : 'Thời gian tự chuyển slide chưa đúng.');
    }
    default: return result(criterion, false, { rule: verification.type }, 'Rule PowerPoint chưa được hỗ trợ đầy đủ hoặc thuộc tính parser không đọc được.', true);
  }
}

export function evaluateRuleCriteria(rubric, document) {
  return rubric.criteria.filter(criterion => criterion.verification_type !== 'ai_review').map(criterion => {
    try {
      const technical = document.type === 'excel' ? evaluateExcel(criterion, document) : document.type === 'word' ? evaluateWord(criterion, document) : document.type === 'powerpoint' ? evaluatePowerPoint(criterion, document) : result(criterion, false, {}, 'Định dạng không hỗ trợ rule.', true);
      if (criterion.verification_type === 'mixed') {
        technical.score = technical.status === 'passed' ? criterion.max_score * Number(criterion.verification?.rule_weight ?? 0.5) : 0;
        technical.max_score = criterion.max_score * Number(criterion.verification?.rule_weight ?? 0.5);
        technical.mixed_parent_max_score = criterion.max_score;
      }
      return technical;
    } catch (error) {
      return result(criterion, false, { error: error.message }, 'Rule gặp lỗi và cần giáo viên kiểm tra.', true);
    }
  });
}
