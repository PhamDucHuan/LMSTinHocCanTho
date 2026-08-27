function normalizeFormula(value) {
  return String(value || '').replace(/^=/, '').replace(/\s+/g, '').replaceAll(';', ',').toUpperCase();
}

function normalizeWordTableFormula(value) {
  return normalizeFormula(String(value || '').split(/\s+\\[#*]\s*/)[0]);
}

function normalizeExcelReference(value) {
  return String(value || '').replace(/^=/, '').replaceAll('$', '').replaceAll("'", '').replace(/\s+/g, '').toUpperCase();
}

function excelRangeBounds(value) {
  const match = normalizeExcelReference(value).match(/^([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?$/);
  if (!match) return null;
  const columnNumber = column => [...column].reduce((sum, char) => sum * 26 + char.charCodeAt(0) - 64, 0);
  return {
    start_column: columnNumber(match[1]), start_row: Number(match[2]),
    end_column: columnNumber(match[3] || match[1]), end_row: Number(match[4] || match[2]),
  };
}

function excelSqrefContains(actualSqref, expectedRange) {
  if (!expectedRange) return true;
  const expected = excelRangeBounds(expectedRange);
  if (!expected) return normalizeExcelReference(actualSqref) === normalizeExcelReference(expectedRange);
  return String(actualSqref || '').split(/\s+/).filter(Boolean).some(item => {
    const actual = excelRangeBounds(item);
    return actual
      && actual.start_column <= expected.start_column && actual.start_row <= expected.start_row
      && actual.end_column >= expected.end_column && actual.end_row >= expected.end_row;
  });
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
    case 'excel_named_range_exists':
    case 'excel_named_range': {
      const namedRanges = document.named_ranges || [];
      const expectedName = String(verification.name || verification.expected_name || '').trim().toLowerCase();
      const expectedReference = verification.reference || verification.expected_reference || verification.refers_to;
      const expectedSheet = String(verification.referenced_sheet || verification.scope_sheet || verification.sheet || '').trim().toLowerCase();
      const expectedScope = String(verification.scope || '').trim().toLowerCase();
      const expectedKind = String(verification.kind || verification.expected_kind || '').trim().toLowerCase();
      const matching = namedRanges.filter(item =>
        (!expectedName || String(item.name || '').toLowerCase() === expectedName)
        && (!expectedReference || [item.normalized_reference, item.refers_to].some(value => normalizeExcelReference(value) === normalizeExcelReference(expectedReference)))
        && (!expectedSheet || [item.referenced_sheet, item.scope_sheet].some(value => String(value || '').toLowerCase() === expectedSheet))
        && (!expectedScope || String(item.scope || '').toLowerCase() === expectedScope)
        && (!expectedKind || String(item.kind || '').toLowerCase() === expectedKind));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: { name: expectedName || null, reference: expectedReference || null, sheet: expectedSheet || null, scope: expectedScope || null }, matched_named_ranges: matching, actual_named_ranges: namedRanges }, passed
        ? 'Đã xác nhận Named Range đúng yêu cầu.'
        : 'Không tìm thấy Named Range đúng tên/phạm vi yêu cầu.');
    }
    case 'excel_data_validation': {
      const validationSheets = sheet ? [sheet] : (document.sheets || []);
      const validations = validationSheets.flatMap(item => (item.data_validations || []).map(validation => ({ ...validation, sheet: item.name })));
      const expectedType = String(verification.expected_type || verification.validation_type || verification.type_name || '').trim().toLowerCase();
      const expectedOperator = String(verification.operator || verification.expected_operator || '').trim().toLowerCase();
      const expectedFormula1 = verification.formula1 ?? verification.expected_formula1;
      const expectedFormula2 = verification.formula2 ?? verification.expected_formula2;
      const matching = validations.filter(validation =>
        excelSqrefContains(validation.range, verification.range)
        && (!expectedType || String(validation.type || '').toLowerCase() === expectedType)
        && (!expectedOperator || String(validation.operator || '').toLowerCase() === expectedOperator)
        && (expectedFormula1 == null || normalizeFormula(validation.formula1) === normalizeFormula(expectedFormula1))
        && (expectedFormula2 == null || normalizeFormula(validation.formula2) === normalizeFormula(expectedFormula2))
        && (verification.allow_blank == null || Boolean(validation.allow_blank) === Boolean(verification.allow_blank))
        && (verification.show_input_message == null || Boolean(validation.show_input_message) === Boolean(verification.show_input_message))
        && (verification.show_error_message == null || Boolean(validation.show_error_message) === Boolean(verification.show_error_message))
        && (verification.dropdown_arrow_visible == null || Boolean(validation.dropdown_arrow_visible) === Boolean(verification.dropdown_arrow_visible))
        && (!verification.error_style || String(validation.error_style || '').toLowerCase() === String(verification.error_style).toLowerCase())
        && (!verification.prompt_title || String(validation.prompt_title || '').toLowerCase() === String(verification.prompt_title).toLowerCase())
        && (!verification.prompt || String(validation.prompt || '').toLowerCase() === String(verification.prompt).toLowerCase())
        && (!verification.error_title || String(validation.error_title || '').toLowerCase() === String(verification.error_title).toLowerCase())
        && (!verification.error || String(validation.error || '').toLowerCase() === String(verification.error).toLowerCase()));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: { sheet: verification.sheet || null, range: verification.range || null, type: expectedType || null, operator: expectedOperator || null, formula1: expectedFormula1 ?? null, formula2: expectedFormula2 ?? null }, matched_validations: matching, actual_validations: validations }, passed
        ? 'Đã xác nhận Data Validation đúng vùng và điều kiện.'
        : 'Không tìm thấy Data Validation đúng vùng/điều kiện yêu cầu.');
    }
    case 'excel_structured_table_exists':
    case 'excel_table_exists': {
      const tables = document.structured_tables || document.sheets?.flatMap(item => item.structured_tables || []) || [];
      const expectedName = String(verification.name || verification.expected_name || '').trim().toLowerCase();
      const expectedSheet = String(verification.sheet || '').trim().toLowerCase();
      const expectedStyle = String(verification.style || verification.expected_style || '').trim().toLowerCase();
      const expectedColumns = Array.isArray(verification.column_names || verification.columns) ? (verification.column_names || verification.columns).map(value => String(value).toLowerCase()) : [];
      const matching = tables.filter(table =>
        (!expectedName || [table.name, table.display_name].some(value => String(value || '').toLowerCase() === expectedName))
        && (!expectedSheet || String(table.sheet || '').toLowerCase() === expectedSheet)
        && (!verification.range || normalizeExcelReference(table.range) === normalizeExcelReference(verification.range))
        && (!expectedStyle || String(table.style?.name || '').toLowerCase() === expectedStyle)
        && (!expectedColumns.length || expectedColumns.every((name, index) => String(table.columns?.[index]?.name || '').toLowerCase() === name))
        && (verification.totals_row_count == null || Number(table.totals_row_count || 0) === Number(verification.totals_row_count)));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: { name: expectedName || null, sheet: expectedSheet || null, range: verification.range || null, style: expectedStyle || null }, matched_tables: matching, actual_tables: tables }, passed
        ? 'Đã xác nhận Excel Table/ListObject đúng yêu cầu.'
        : 'Không tìm thấy Excel Table/ListObject đúng yêu cầu.');
    }
    case 'excel_data_table_exists':
    case 'excel_what_if_data_table_exists': {
      const dataTables = document.what_if_data_tables || document.sheets?.flatMap(item => (item.what_if_data_tables || []).map(table => ({ ...table, sheet: item.name }))) || [];
      const expectedSheet = String(verification.sheet || '').trim().toLowerCase();
      const matching = dataTables.filter(table =>
        (!expectedSheet || String(table.sheet || '').toLowerCase() === expectedSheet)
        && (!verification.range || normalizeExcelReference(table.range) === normalizeExcelReference(verification.range))
        && (verification.two_variable == null || Boolean(table.two_variable) === Boolean(verification.two_variable))
        && (!verification.row_input_cell || normalizeExcelReference(table.row_input_cell) === normalizeExcelReference(verification.row_input_cell))
        && (!verification.column_input_cell || normalizeExcelReference(table.column_input_cell) === normalizeExcelReference(verification.column_input_cell)));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: { sheet: verification.sheet || null, range: verification.range || null, two_variable: verification.two_variable ?? null, row_input_cell: verification.row_input_cell || null, column_input_cell: verification.column_input_cell || null }, matched_data_tables: matching, actual_data_tables: dataTables }, passed
        ? 'Đã xác nhận What-If Analysis Data Table.'
        : 'Không tìm thấy What-If Analysis Data Table đúng yêu cầu.');
    }
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
    case 'word_toc_exists':
    case 'word_automatic_toc': {
      const toc = document.table_of_contents || {};
      const expectedFrom = verification.heading_levels?.from ?? verification.from_level;
      const expectedTo = verification.heading_levels?.to ?? verification.to_level;
      const matchingEntry = (toc.entries || []).find(entry =>
        (expectedFrom == null || Number(entry.heading_levels?.from) === Number(expectedFrom))
        && (expectedTo == null || Number(entry.heading_levels?.to) === Number(expectedTo)));
      const passed = toc.automatic === true && (expectedFrom == null && expectedTo == null ? true : Boolean(matchingEntry));
      return result(criterion, passed, { table_of_contents: toc, expected_heading_levels: { from: expectedFrom ?? null, to: expectedTo ?? null } }, passed
        ? 'Đã xác nhận mục lục tự động bằng field TOC.'
        : 'Không tìm thấy field TOC tự động đúng yêu cầu.');
    }
    case 'word_smartart_exists': {
      const smartart = document.smartart || {};
      const minimum = Math.max(1, Number(verification.minimum_count || verification.expected_count || 1) || 1);
      const expectedText = String(verification.text_contains || '').trim().toLowerCase();
      const textMatched = !expectedText || (smartart.diagrams || []).some(diagram => (diagram.text || []).some(text => String(text).toLowerCase().includes(expectedText)));
      const passed = Number(smartart.count || 0) >= minimum && textMatched;
      return result(criterion, passed, { smartart, minimum_count: minimum, text_contains: expectedText || null }, passed
        ? 'Đã xác nhận SmartArt từ cấu trúc diagram của Word.'
        : 'Không tìm thấy SmartArt đúng yêu cầu.');
    }
    case 'word_form_exists':
    case 'word_form_control_exists': {
      const controls = document.form_controls || [];
      const expectedType = String(verification.control_type || verification.expected_type || '').trim().toLowerCase();
      const expectedName = String(verification.name || verification.title || verification.tag || '').trim().toLowerCase();
      const matchingControls = controls.filter(control =>
        (!expectedType || String(control.type || '').toLowerCase() === expectedType)
        && (!expectedName || [control.name, control.title, control.tag].some(value => String(value || '').toLowerCase().includes(expectedName))));
      const minimum = Math.max(1, Number(verification.minimum_count || verification.expected_count || 1) || 1);
      const passed = matchingControls.length >= minimum;
      return result(criterion, passed, { controls, expected_type: expectedType || null, expected_name: expectedName || null, minimum_count: minimum, matched_count: matchingControls.length }, passed
        ? 'Đã xác nhận biểu mẫu/control trong Word.'
        : 'Không tìm thấy biểu mẫu/control đúng yêu cầu.');
    }
    case 'word_table_formula_exists':
    case 'word_table_formula': {
      const formulas = document.table_formulas || document.tables?.flatMap(table => table.formulas || []) || [];
      const tableIndex = verification.table_index == null ? null : Number(verification.table_index);
      const row = verification.row == null ? null : Number(verification.row);
      const column = verification.column == null ? null : Number(verification.column);
      const expectedFormula = normalizeWordTableFormula(verification.expected_formula || verification.formula);
      const expectedFunction = String(verification.expected_function || '').trim().toUpperCase();
      const matching = formulas.filter(formula => {
        const actual = normalizeWordTableFormula(formula.formula || formula.instruction);
        return (tableIndex == null || Number(formula.table_index) === tableIndex)
          && (row == null || Number(formula.row) === row)
          && (column == null || Number(formula.column) === column)
          && (!expectedFormula || actual === expectedFormula)
          && (!expectedFunction || actual.startsWith(`${expectedFunction}(`));
      });
      const passed = matching.length > 0;
      return result(criterion, passed, {
        expected: { table_index: tableIndex, row, column, formula: expectedFormula || null, function: expectedFunction || null },
        matched_formulas: matching,
        actual_formulas: formulas,
      }, passed ? 'Đã xác nhận công thức thật trong ô bảng Word.' : 'Không tìm thấy công thức bảng Word đúng yêu cầu.');
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
    case 'ppt_theme_exists': {
      const theme = document.theme || {};
      const expected = String(verification.expected || verification.name || '').trim();
      const passed = theme.status === 'detected' && (!expected || String(theme.name || '').toLowerCase().includes(expected.toLowerCase()));
      return result(criterion, passed, { theme, expected: expected || null }, passed
        ? `Đã đọc được Theme${theme.name ? `: ${theme.name}` : ''}.`
        : 'Chưa đọc được Theme PowerPoint yêu cầu.', !passed);
    }
    case 'ppt_layout_exists': {
      const layout = slide?.layout || {};
      return result(criterion, layout.status === 'detected', { slide: verification.slide, layout }, layout.status === 'detected'
        ? `Đã đọc được Slide Layout${layout.name ? `: ${layout.name}` : ''}.`
        : 'Chưa đọc được Slide Layout.', true);
    }
    case 'ppt_slide_master_exists': {
      const masters = document.slide_masters || [];
      const expectedName = String(verification.name || verification.expected_name || '').trim().toLowerCase();
      const expectedPlaceholder = String(verification.placeholder_type || '').trim().toLowerCase();
      const expectedLayout = String(verification.layout_name || '').trim().toLowerCase();
      const minimumObjects = Math.max(0, Number(verification.minimum_object_count || 0) || 0);
      const matching = masters.filter(master =>
        (!expectedName || String(master.name || '').toLowerCase().includes(expectedName))
        && (!expectedPlaceholder || (master.placeholders || []).some(item => String(item.type || '').toLowerCase() === expectedPlaceholder))
        && (!expectedLayout || (master.layouts || []).some(item => String(item.name || '').toLowerCase().includes(expectedLayout)))
        && (minimumObjects === 0 || Number(master.object_count || 0) >= minimumObjects)
        && (verification.background == null || Boolean(master.background) === Boolean(verification.background))
        && (verification.slide_number == null || Boolean(master.header_footer?.slide_number) === Boolean(verification.slide_number))
        && (verification.footer == null || Boolean(master.header_footer?.footer) === Boolean(verification.footer)));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: { name: expectedName || null, placeholder_type: expectedPlaceholder || null, layout_name: expectedLayout || null, minimum_object_count: minimumObjects, background: verification.background ?? null }, matched_masters: matching, actual_masters: masters }, passed
        ? 'Đã xác nhận cấu trúc Slide Master đúng yêu cầu.'
        : 'Không tìm thấy Slide Master đúng cấu trúc yêu cầu.');
    }
    case 'ppt_master_object_exists': {
      const masters = document.slide_masters || [];
      const expectedMaster = String(verification.master_name || '').trim().toLowerCase();
      const expectedType = String(verification.object_type || verification.expected_type || '').trim().toLowerCase();
      const expectedText = String(verification.text_contains || '').trim().toLowerCase();
      const expectedName = String(verification.object_name || '').trim().toLowerCase();
      const matching = masters.flatMap(master => (master.objects || []).filter(object =>
        (!expectedMaster || String(master.name || '').toLowerCase().includes(expectedMaster))
        && (!expectedType || String(object.type || '').toLowerCase() === expectedType)
        && (!expectedText || String(object.text || '').toLowerCase().includes(expectedText))
        && (!expectedName || String(object.name || '').toLowerCase().includes(expectedName)))
        .map(object => ({ master_file: master.file, master_name: master.name, object })));
      const minimum = Math.max(1, Number(verification.minimum_count || 1) || 1);
      const passed = matching.length >= minimum;
      return result(criterion, passed, { expected: { master_name: expectedMaster || null, object_type: expectedType || null, text_contains: expectedText || null, object_name: expectedName || null, minimum_count: minimum }, matched_objects: matching }, passed
        ? 'Đã xác nhận đối tượng được đặt trên Slide Master.'
        : 'Không tìm thấy đối tượng trên Slide Master đúng yêu cầu.');
    }
    case 'ppt_master_text_style': {
      const masters = document.slide_masters || [];
      const styleName = String(verification.style || verification.style_name || 'body').trim().toLowerCase();
      const expectedLevel = Number(verification.level || 1);
      const matching = masters.flatMap(master => (master.text_styles?.[styleName] || []).filter(level =>
        Number(level.level) === expectedLevel
        && (!verification.font_family || String(level.font_family || '').toLowerCase() === String(verification.font_family).toLowerCase())
        && (verification.font_size == null || Number(level.font_size) === Number(verification.font_size))
        && (verification.bold == null || Boolean(level.bold) === Boolean(verification.bold))
        && (!verification.alignment || String(level.alignment || '').toLowerCase() === String(verification.alignment).toLowerCase()))
        .map(level => ({ master_file: master.file, master_name: master.name, style: styleName, level })));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: verification, matched_styles: matching, actual_masters: masters.map(master => ({ file: master.file, name: master.name, text_styles: master.text_styles })) }, passed
        ? 'Đã xác nhận định dạng chữ trên Slide Master.'
        : 'Không tìm thấy định dạng chữ Slide Master đúng yêu cầu.');
    }
    case 'ppt_custom_slide_show_exists': {
      const shows = document.custom_slide_shows || [];
      const expectedName = String(verification.name || verification.expected_name || '').trim().toLowerCase();
      const expectedSlides = Array.isArray(verification.slides || verification.expected_slides) ? (verification.slides || verification.expected_slides).map(Number) : [];
      const matching = shows.filter(show =>
        (!expectedName || String(show.name || '').toLowerCase() === expectedName)
        && (verification.slide_count == null || Number(show.slide_count || 0) === Number(verification.slide_count))
        && (!expectedSlides.length || (expectedSlides.length === (show.slides || []).length && expectedSlides.every((number, index) => Number(show.slides[index]) === number))));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: { name: expectedName || null, slides: expectedSlides, slide_count: verification.slide_count ?? null }, matched_custom_shows: matching, actual_custom_shows: shows }, passed
        ? 'Đã xác nhận Custom Slide Show và đúng thứ tự slide.'
        : 'Không tìm thấy Custom Slide Show đúng tên/thứ tự yêu cầu.');
    }
    case 'ppt_internal_hyperlink_exists':
    case 'ppt_slide_link_exists': {
      const links = document.internal_hyperlinks || document.slides?.flatMap(item => (item.internal_hyperlinks || []).map(link => ({ ...link, source_slide: item.number }))) || [];
      const sourceSlide = verification.source_slide ?? verification.slide;
      const targetSlide = verification.target_slide ?? verification.expected_target_slide;
      const expectedText = String(verification.text_contains || '').trim().toLowerCase();
      const expectedName = String(verification.object_name || '').trim().toLowerCase();
      const expectedTrigger = String(verification.trigger || '').trim().toLowerCase();
      const expectedNavigation = String(verification.navigation || '').trim().toLowerCase();
      const matching = links.filter(link =>
        (sourceSlide == null || Number(link.source_slide) === Number(sourceSlide))
        && (targetSlide == null || Number(link.target_slide) === Number(targetSlide))
        && (!expectedText || String(link.object_text || '').toLowerCase().includes(expectedText))
        && (!expectedName || String(link.object_name || '').toLowerCase().includes(expectedName))
        && (!expectedTrigger || String(link.trigger || '').toLowerCase() === expectedTrigger)
        && (!expectedNavigation || String(link.navigation || '').toLowerCase() === expectedNavigation));
      const passed = matching.length > 0;
      return result(criterion, passed, { expected: { source_slide: sourceSlide ?? null, target_slide: targetSlide ?? null, text_contains: expectedText || null, object_name: expectedName || null, trigger: expectedTrigger || null, navigation: expectedNavigation || null }, matched_links: matching, actual_internal_links: links }, passed
        ? 'Đã xác nhận liên kết/action nội bộ đến slide.'
        : 'Không tìm thấy liên kết nội bộ đúng slide nguồn–đích.');
    }
    case 'ppt_background_exists': {
      const background = slide?.background || {};
      const passed = background.status === 'detected';
      return result(criterion, passed, { slide: verification.slide, background }, passed
        ? `Đã phát hiện nền kế thừa từ ${background.source || 'PowerPoint'}.`
        : 'Slide không có nền tùy chỉnh rõ ràng; có thể đang dùng nền mặc định của Theme.', true);
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
      const summary = slide?.animation_summary || {};
      const hasEffect = animations.length > 0
        || Number(summary.effect_count || 0) > 0
        || summary.timing_present === true
        || summary.has_effects === true;
      return result(criterion, hasEffect, {
        slide: verification.slide,
        policy: 'powerpoint_effect_presence',
        animations,
        animation_summary: summary,
      }, hasEffect
        ? 'Slide đã có hiệu ứng; không yêu cầu trùng tên hiệu ứng, đối tượng hoặc thời lượng.'
        : 'Slide chưa có dữ liệu animation/timing để xác nhận hiệu ứng.');
    }
    case 'ppt_transition_exists':
      return result(criterion, Boolean(slide?.transition?.exists), { slide: verification.slide, transition: slide?.transition || null }, slide?.transition?.exists ? 'Đã đọc được hiệu ứng chuyển slide.' : 'Không có hiệu ứng chuyển slide.');
    case 'ppt_transition_timing': {
      const expectedMs = Number(verification.expected_ms ?? Number(verification.expected_seconds || 0) * 1000);
      const actualMs = slide?.transition?.advance_after_ms;
      const passed = Boolean(slide?.transition?.exists);
      return result(criterion, passed, {
        slide: verification.slide,
        policy: 'powerpoint_effect_presence',
        expected_ms: expectedMs,
        actual_ms: actualMs,
        transition: slide?.transition || null,
      }, passed
        ? 'Slide đã có hiệu ứng chuyển tiếp; thời gian chỉ được ghi nhận tham khảo và không làm mất điểm.'
        : 'Slide chưa có hiệu ứng chuyển tiếp.');
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
