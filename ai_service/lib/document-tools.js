import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import AdmZip from 'adm-zip';
import mammoth from 'mammoth';

const emuToInch = value => Math.round((Number(value || 0) / 914400) * 1000) / 1000;
const decodeXmlEntities = value => String(value || '')
  .replace(/&#x([0-9a-f]+);/gi, (_, code) => String.fromCodePoint(Number.parseInt(code, 16)))
  .replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)))
  .replaceAll('&quot;', '"')
  .replaceAll('&apos;', "'")
  .replaceAll('&lt;', '<')
  .replaceAll('&gt;', '>')
  .replaceAll('&amp;', '&');
const xmlText = value => decodeXmlEntities(String(value || '').replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
const attr = (value, name) => String(value || '').match(new RegExp(`\\b${name}="([^"]*)"`, 'i'))?.[1] ?? null;
const tag = (value, name) => String(value || '').match(new RegExp(`<${name}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${name}>`, 'i'))?.[1] ?? '';
const tags = (value, name) => [...String(value || '').matchAll(new RegExp(`<${name}(?:\\s([^>]*))?>([\\s\\S]*?)<\\/${name}>`, 'gi'))];
const hash = value => crypto.createHash('sha256').update(value).digest('hex');

function normalizeZipPath(value) {
  const parts = [];
  for (const part of String(value || '').replace(/\\/g, '/').split('/')) {
    if (!part || part === '.') continue;
    if (part === '..') parts.pop(); else parts.push(part);
  }
  return parts.join('/');
}

function relationshipDetails(zip, relationshipFile, ownerFile) {
  const xml = zip.getEntry(relationshipFile)?.getData().toString('utf8') || '';
  const ownerDirectory = String(ownerFile || '').split('/').slice(0, -1).join('/');
  return [...xml.matchAll(/<Relationship\b([^>]*)\/?>(?:<\/Relationship>)?/gi)].map(match => {
    const attributes = match[1];
    const target = attr(attributes, 'Target');
    const targetMode = attr(attributes, 'TargetMode') || 'Internal';
    return {
      id: attr(attributes, 'Id'),
      type: attr(attributes, 'Type') || '',
      target,
      target_mode: targetMode,
      resolved_target: target ? (targetMode.toLowerCase() === 'external' ? target : normalizeZipPath(`${ownerDirectory}/${target}`)) : null,
    };
  }).filter(item => item.id && item.resolved_target);
}

function relationshipTargets(zip, relationshipFile, ownerFile) {
  return Object.fromEntries(relationshipDetails(zip, relationshipFile, ownerFile).map(item => [item.id, item.resolved_target]));
}

function parseWordFields(source) {
  const xml = String(source || '');
  const fields = [];

  for (const match of xml.matchAll(/<w:fldSimple\b([^>]*)>([\s\S]*?)<\/w:fldSimple>/gi)) {
    const instruction = decodeXmlEntities(attr(match[1], 'w:instr') || attr(match[1], 'instr') || '').replace(/\s+/g, ' ').trim();
    if (!instruction) continue;
    fields.push({
      kind: 'simple',
      instruction,
      result_text: tags(match[2], 'w:t').map(item => xmlText(item[2])).join(' ').trim(),
    });
  }

  const stack = [];
  const tokenPattern = /<w:fldChar\b([^>]*)\/?\s*>|<w:(instrText|t)\b[^>]*>([\s\S]*?)<\/w:\2>/gi;
  for (const match of xml.matchAll(tokenPattern)) {
    if (match[1] != null) {
      const fieldType = attr(match[1], 'w:fldCharType') || attr(match[1], 'fldCharType');
      if (fieldType === 'begin') {
        stack.push({ instruction_parts: [], result_parts: [], separated: false });
      } else if (fieldType === 'separate' && stack.length) {
        stack[stack.length - 1].separated = true;
      } else if (fieldType === 'end' && stack.length) {
        const field = stack.pop();
        const instruction = decodeXmlEntities(field.instruction_parts.join('')).replace(/\s+/g, ' ').trim();
        if (instruction) {
          fields.push({
            kind: 'complex',
            instruction,
            result_text: field.result_parts.join(' ').replace(/\s+/g, ' ').trim(),
          });
        }
      }
      continue;
    }

    if (match[2] === 'instrText') {
      if (stack.length && !stack[stack.length - 1].separated) stack[stack.length - 1].instruction_parts.push(xmlText(match[3]));
    } else if (match[2] === 't') {
      const text = xmlText(match[3]);
      if (text) stack.filter(field => field.separated).forEach(field => field.result_parts.push(text));
    }
  }

  return fields;
}

function parseWordContentControls(xml) {
  const controls = [];
  for (const match of String(xml || '').matchAll(/<w:sdt\b[^>]*>([\s\S]*?)<\/w:sdt>/gi)) {
    const body = match[1];
    const properties = tag(body, 'w:sdtPr');
    if (!properties || /<w:docPartGallery\b/i.test(properties)) continue;
    const type = /<(?:w14|w15|w):checkbox\b/i.test(properties) ? 'checkbox'
      : /<w:dropDownList\b/i.test(properties) ? 'dropdown'
        : /<w:comboBox\b/i.test(properties) ? 'combobox'
          : /<w:date\b/i.test(properties) ? 'date'
            : /<w:picture\b/i.test(properties) ? 'picture'
              : /<w:repeatingSection\b/i.test(properties) ? 'repeating_section'
                : /<w:richText\b/i.test(properties) ? 'rich_text'
                  : /<w:text\b/i.test(properties) ? 'text'
                    : 'content_control';
    const checkedAttributes = properties.match(/<(?:w14|w15):checked\b([^>]*)/i)?.[1] || '';
    const checkedValue = attr(checkedAttributes, 'w14:val') || attr(checkedAttributes, 'w15:val') || attr(checkedAttributes, 'val');
    controls.push({
      kind: 'content_control',
      type,
      id: attr(properties.match(/<w:id\b([^>]*)/i)?.[1], 'w:val'),
      title: decodeXmlEntities(attr(properties.match(/<w:alias\b([^>]*)/i)?.[1], 'w:val') || ''),
      tag: decodeXmlEntities(attr(properties.match(/<w:tag\b([^>]*)/i)?.[1], 'w:val') || ''),
      checked: type === 'checkbox' ? ['1', 'true', 'on'].includes(String(checkedValue || '').toLowerCase()) : null,
      content: xmlText(tag(body, 'w:sdtContent')),
    });
  }
  return controls;
}

function parseLegacyWordFormControls(xml, fields) {
  const controls = tags(xml, 'w:ffData').map(match => {
    const body = match[2];
    const type = /<w:checkBox\b/i.test(body) ? 'checkbox'
      : /<w:ddList\b/i.test(body) ? 'dropdown'
        : /<w:textInput\b/i.test(body) ? 'text' : 'legacy_field';
    const checkedAttributes = body.match(/<w:(?:checked|default)\b([^>]*)/i)?.[1] || '';
    return {
      kind: 'legacy_form_field',
      type,
      name: decodeXmlEntities(attr(body.match(/<w:name\b([^>]*)/i)?.[1], 'w:val') || ''),
      checked: type === 'checkbox' ? ['1', 'true', 'on'].includes(String(attr(checkedAttributes, 'w:val') || '').toLowerCase()) : null,
    };
  });
  const knownTypes = new Set(controls.map(control => control.type));
  for (const field of fields.filter(item => /^FORM(?:CHECKBOX|DROPDOWN|TEXT)\b/i.test(item.instruction))) {
    const type = /^FORMCHECKBOX\b/i.test(field.instruction) ? 'checkbox'
      : /^FORMDROPDOWN\b/i.test(field.instruction) ? 'dropdown' : 'text';
    if (!knownTypes.has(type)) controls.push({ kind: 'legacy_form_field', type, name: '', checked: null });
  }
  return controls;
}

function parseWordSmartArt(zip, documentXml) {
  const relationships = relationshipTargets(zip, 'word/_rels/document.xml.rels', 'word/document.xml');
  const dataEntries = zip.getEntries().filter(entry => /^word\/diagrams\/data\d+\.xml$/i.test(entry.entryName));
  const relatedDataFiles = new Set(Object.values(relationships).filter(target => /^word\/diagrams\/data\d+\.xml$/i.test(target)));
  const references = [...String(documentXml || '').matchAll(/<dgm:relIds\b([^>]*)\/?\s*>/gi)].map(match => {
    const attributes = match[1];
    const dataFile = relationships[attr(attributes, 'r:dm')] || null;
    const layoutFile = relationships[attr(attributes, 'r:lo')] || null;
    const styleFile = relationships[attr(attributes, 'r:qs')] || null;
    const colorsFile = relationships[attr(attributes, 'r:cs')] || null;
    const dataXml = dataFile ? (zip.getEntry(dataFile)?.getData().toString('utf8') || '') : '';
    const layoutXml = layoutFile ? (zip.getEntry(layoutFile)?.getData().toString('utf8') || '') : '';
    return {
      data_file: dataFile,
      layout_file: layoutFile,
      style_file: styleFile,
      colors_file: colorsFile,
      layout_name: decodeXmlEntities(attr(layoutXml.match(/<dgm:layoutDef\b([^>]*)/i)?.[1], 'uniqueId') || attr(layoutXml.match(/<dgm:title\b([^>]*)/i)?.[1], 'val') || ''),
      text: tags(dataXml, 'a:t').map(item => xmlText(item[2])).filter(Boolean),
    };
  });
  const diagrams = references.length ? references : dataEntries.filter(entry => relatedDataFiles.has(entry.entryName)).map(entry => {
    const dataXml = entry.getData().toString('utf8');
    return { data_file: entry.entryName, layout_file: null, style_file: null, colors_file: null, layout_name: '', text: tags(dataXml, 'a:t').map(item => xmlText(item[2])).filter(Boolean) };
  });
  return {
    count: diagrams.length,
    diagrams,
    data_file_count: dataEntries.length,
    drawing_file_count: zip.getEntries().filter(entry => /^word\/diagrams\/drawing\d+\.xml$/i.test(entry.entryName)).length,
  };
}

function presentationThemeDetails(zip) {
  const entry = zip.getEntries().find(item => /^ppt\/theme\/theme\d+\.xml$/i.test(item.entryName));
  if (!entry) return { status: 'not_found', name: null, file: null };
  const xml = entry.getData().toString('utf8');
  return {
    status: 'detected',
    name: attr(xml.match(/<a:theme\b([^>]*)/i)?.[1], 'name') || null,
    file: entry.entryName,
    color_scheme: attr(xml.match(/<a:clrScheme\b([^>]*)/i)?.[1], 'name') || null,
  };
}

function parseExcelStyles(zip) {
  const entry = zip.getEntry('xl/styles.xml');
  if (!entry) return [];
  const xml = entry.getData().toString('utf8');
  const fonts = tags(tag(xml, 'fonts'), 'font').map(match => ({
    name: attr(match[2], 'val') || attr(match[2].match(/<name\b([^>]*)/i)?.[1], 'val'),
    size: Number(attr(match[2].match(/<sz\b([^>]*)/i)?.[1], 'val') || 0) || null,
    bold: /<b(?:\s|\/|>)/i.test(match[2]),
    italic: /<i(?:\s|\/|>)/i.test(match[2]),
    underline: /<u(?:\s|\/|>)/i.test(match[2]),
    color: attr(match[2].match(/<color\b([^>]*)/i)?.[1], 'rgb'),
  }));
  const fills = tags(tag(xml, 'fills'), 'fill').map(match => ({
    pattern: attr(match[2].match(/<patternFill\b([^>]*)/i)?.[1], 'patternType'),
    color: attr(match[2].match(/<fgColor\b([^>]*)/i)?.[1], 'rgb'),
  }));
  const borders = tags(tag(xml, 'borders'), 'border').map(match => ({
    left: attr(match[2].match(/<left\b([^>]*)/i)?.[1], 'style'),
    right: attr(match[2].match(/<right\b([^>]*)/i)?.[1], 'style'),
    top: attr(match[2].match(/<top\b([^>]*)/i)?.[1], 'style'),
    bottom: attr(match[2].match(/<bottom\b([^>]*)/i)?.[1], 'style'),
  }));
  const numFormats = Object.fromEntries(tags(tag(xml, 'numFmts'), 'numFmt').map(match => [attr(match[1], 'numFmtId'), attr(match[1], 'formatCode')]));
  return tags(tag(xml, 'cellXfs'), 'xf').map(match => ({
    font: fonts[Number(attr(match[1], 'fontId') || 0)] || {},
    fill: fills[Number(attr(match[1], 'fillId') || 0)] || {},
    border: borders[Number(attr(match[1], 'borderId') || 0)] || {},
    number_format: numFormats[attr(match[1], 'numFmtId')] || attr(match[1], 'numFmtId'),
    alignment: {
      horizontal: attr(match[2].match(/<alignment\b([^>]*)/i)?.[1], 'horizontal'),
      vertical: attr(match[2].match(/<alignment\b([^>]*)/i)?.[1], 'vertical'),
      wrap_text: attr(match[2].match(/<alignment\b([^>]*)/i)?.[1], 'wrapText') === '1',
    },
  }));
}

const xmlBoolean = value => ['1', 'true', 'on'].includes(String(value || '').toLowerCase());

function parseExcelDataValidations(xml) {
  const parseMatches = (pattern, namespace = '') => [...String(xml || '').matchAll(pattern)].map(match => {
    const attributes = match[1] || '';
    const body = match[2] || '';
    const prefix = namespace ? `${namespace}:` : '';
    return {
      range: attr(attributes, 'sqref') || xmlText(tag(body, 'xm:sqref')),
      type: attr(attributes, 'type') || 'none',
      operator: attr(attributes, 'operator') || null,
      formula1: xmlText(tag(body, `${prefix}formula1`)) || null,
      formula2: xmlText(tag(body, `${prefix}formula2`)) || null,
      allow_blank: xmlBoolean(attr(attributes, 'allowBlank')),
      show_dropdown_attribute: attr(attributes, 'showDropDown') == null ? null : xmlBoolean(attr(attributes, 'showDropDown')),
      dropdown_arrow_visible: attr(attributes, 'showDropDown') == null ? null : !xmlBoolean(attr(attributes, 'showDropDown')),
      show_input_message: xmlBoolean(attr(attributes, 'showInputMessage')),
      show_error_message: xmlBoolean(attr(attributes, 'showErrorMessage')),
      error_style: attr(attributes, 'errorStyle') || null,
      prompt_title: decodeXmlEntities(attr(attributes, 'promptTitle') || ''),
      prompt: decodeXmlEntities(attr(attributes, 'prompt') || ''),
      error_title: decodeXmlEntities(attr(attributes, 'errorTitle') || ''),
      error: decodeXmlEntities(attr(attributes, 'error') || ''),
      ime_mode: attr(attributes, 'imeMode') || null,
      source: namespace ? `${namespace}:dataValidation` : 'dataValidation',
    };
  });
  return [
    ...parseMatches(/<dataValidation\b([^>]*?)(?:\/>|>([\s\S]*?)<\/dataValidation>)/gi),
    ...parseMatches(/<x14:dataValidation\b([^>]*?)(?:\/>|>([\s\S]*?)<\/x14:dataValidation>)/gi, 'x14'),
  ];
}

function parseExcelStructuredTable(zip, file, sheetName) {
  const xml = zip.getEntry(file)?.getData().toString('utf8') || '';
  const attributes = xml.match(/<table\b([^>]*)>/i)?.[1] || '';
  const styleAttributes = xml.match(/<tableStyleInfo\b([^>]*)\/?\s*>/i)?.[1] || '';
  const columns = [...xml.matchAll(/<tableColumn\b([^>]*?)(?:\/>|>([\s\S]*?)<\/tableColumn>)/gi)].map(match => ({
    id: Number(attr(match[1], 'id')) || null,
    name: decodeXmlEntities(attr(match[1], 'name') || ''),
    totals_row_label: decodeXmlEntities(attr(match[1], 'totalsRowLabel') || ''),
    totals_row_function: attr(match[1], 'totalsRowFunction') || null,
    calculated_column_formula: xmlText(tag(match[2], 'calculatedColumnFormula')) || null,
    totals_row_formula: xmlText(tag(match[2], 'totalsRowFormula')) || null,
  }));
  return {
    file,
    sheet: sheetName,
    id: Number(attr(attributes, 'id')) || null,
    name: decodeXmlEntities(attr(attributes, 'name') || ''),
    display_name: decodeXmlEntities(attr(attributes, 'displayName') || ''),
    range: attr(attributes, 'ref') || null,
    header_row_count: attr(attributes, 'headerRowCount') == null ? 1 : Number(attr(attributes, 'headerRowCount')),
    totals_row_count: Number(attr(attributes, 'totalsRowCount') || 0),
    auto_filter: attr(xml.match(/<autoFilter\b([^>]*)/i)?.[1], 'ref') || null,
    columns,
    style: {
      name: attr(styleAttributes, 'name') || null,
      show_first_column: xmlBoolean(attr(styleAttributes, 'showFirstColumn')),
      show_last_column: xmlBoolean(attr(styleAttributes, 'showLastColumn')),
      show_row_stripes: xmlBoolean(attr(styleAttributes, 'showRowStripes')),
      show_column_stripes: xmlBoolean(attr(styleAttributes, 'showColumnStripes')),
    },
  };
}

function parseExcelDefinedNames(workbookXml, sheetDefinitions) {
  return tags(tag(workbookXml, 'definedNames'), 'definedName').map(match => {
    const name = decodeXmlEntities(attr(match[1], 'name') || '');
    const localSheetIdValue = attr(match[1], 'localSheetId');
    const localSheetId = localSheetIdValue == null ? null : Number(localSheetIdValue);
    const refersTo = xmlText(match[2]);
    const sheetReference = refersTo.match(/^=?('(?:[^']|'')+'|[^!]+)!([\s\S]+)$/);
    const referencedSheet = sheetReference
      ? sheetReference[1].replace(/^'|'$/g, '').replaceAll("''", "'")
      : null;
    const reference = sheetReference ? sheetReference[2] : refersTo.replace(/^=/, '');
    const normalizedReference = reference.replaceAll('$', '').replace(/\s+/g, '').toUpperCase();
    const rangeLike = /^(?:[A-Z]+\d+)(?::[A-Z]+\d+)?(?:,(?:[A-Z]+\d+)(?::[A-Z]+\d+)?)*$/i.test(normalizedReference);
    return {
      name,
      refers_to: refersTo,
      normalized_reference: normalizedReference,
      referenced_sheet: referencedSheet,
      scope: localSheetId == null ? 'workbook' : 'worksheet',
      scope_sheet: localSheetId == null ? null : (sheetDefinitions[localSheetId]?.name || null),
      local_sheet_id: localSheetId,
      hidden: xmlBoolean(attr(match[1], 'hidden')),
      comment: decodeXmlEntities(attr(match[1], 'comment') || ''),
      built_in: name.toLowerCase().startsWith('_xlnm.'),
      kind: rangeLike ? 'range' : (/^["'].*["']$|^-?\d+(?:\.\d+)?$/.test(reference) ? 'constant' : 'formula'),
    };
  });
}

function parseExcel(filePath) {
  const zip = new AdmZip(filePath);
  const sharedEntry = zip.getEntry('xl/sharedStrings.xml');
  const shared = sharedEntry ? tags(sharedEntry.getData().toString('utf8'), 'si').map(match => xmlText(match[2])) : [];
  const styles = parseExcelStyles(zip);
  const workbookXml = zip.getEntry('xl/workbook.xml')?.getData().toString('utf8') || '';
  const workbookRelationships = relationshipTargets(zip, 'xl/_rels/workbook.xml.rels', 'xl/workbook.xml');
  const sheetDefinitions = [...workbookXml.matchAll(/<sheet\b([^>]*)\/?>/gi)].map(match => ({
    name: attr(match[1], 'name'),
    state: attr(match[1], 'state') || 'visible',
    relationship_id: attr(match[1], 'r:id'),
  }));
  const definedNames = parseExcelDefinedNames(workbookXml, sheetDefinitions);
  const discoveredSheetEntries = zip.getEntries().filter(entry => /^xl\/worksheets\/sheet\d+\.xml$/i.test(entry.entryName)).sort((a, b) => a.entryName.localeCompare(b.entryName, undefined, { numeric: true }));
  const relatedSheetEntries = sheetDefinitions.map(definition => {
    const target = workbookRelationships[definition.relationship_id];
    return target ? zip.getEntry(target) : null;
  }).filter(Boolean);
  const sheetEntries = relatedSheetEntries.length === sheetDefinitions.length ? relatedSheetEntries : discoveredSheetEntries;
  const sheets = sheetEntries.map((entry, index) => {
    const xml = entry.getData().toString('utf8');
    const cells = {};
    const whatIfDataTables = [];
    for (const match of xml.matchAll(/<c\b([^>]*)>([\s\S]*?)<\/c>/gi)) {
      const address = attr(match[1], 'r');
      if (!address) continue;
      const type = attr(match[1], 't') || 'number';
      const formulaMatch = match[2].match(/<f\b([^>]*?)(?:\/>|>([\s\S]*?)<\/f>)/i);
      const formulaAttributes = formulaMatch?.[1] || '';
      const formula = xmlText(formulaMatch?.[2]) || null;
      const formulaType = attr(formulaAttributes, 't') || (formula ? 'normal' : null);
      let value = xmlText(tag(match[2], type === 'inlineStr' ? 'is' : 'v'));
      if (type === 's') value = shared[Number(value)] ?? value;
      cells[address] = {
        address, value, data_type: type, formula,
        formula_type: formulaType,
        formula_reference: attr(formulaAttributes, 'ref') || null,
        cached_result: formula ? value : null,
        number_format: styles[Number(attr(match[1], 's') || 0)]?.number_format ?? null,
        style: styles[Number(attr(match[1], 's') || 0)] || {},
        hyperlink: null,
      };
      if (String(formulaType).toLowerCase() === 'datatable') {
        whatIfDataTables.push({
          cell: address,
          range: attr(formulaAttributes, 'ref') || address,
          two_variable: xmlBoolean(attr(formulaAttributes, 'dt2D')),
          row_oriented: xmlBoolean(attr(formulaAttributes, 'dtr')),
          input_cell_1: attr(formulaAttributes, 'r1') || null,
          input_cell_2: attr(formulaAttributes, 'r2') || null,
          row_input_cell: attr(formulaAttributes, 'r1') || null,
          column_input_cell: attr(formulaAttributes, 'r2') || null,
          recalculate_always: xmlBoolean(attr(formulaAttributes, 'ca')),
        });
      }
    }
    const dimension = attr(xml.match(/<dimension\b([^>]*)/i)?.[1], 'ref') || '';
    const rows = [...xml.matchAll(/<row\b([^>]*)/gi)].map(match => ({ index: Number(attr(match[1], 'r')), height: Number(attr(match[1], 'ht')) || null, hidden: attr(match[1], 'hidden') === '1' }));
    const columns = [...xml.matchAll(/<col\b([^>]*)\/?>/gi)].map(match => ({ min: Number(attr(match[1], 'min')), max: Number(attr(match[1], 'max')), width: Number(attr(match[1], 'width')) || null, hidden: attr(match[1], 'hidden') === '1' }));
    const sheetName = sheetDefinitions[index]?.name || `Sheet${index + 1}`;
    const sheetRelationships = relationshipTargets(zip, `xl/worksheets/_rels/${path.basename(entry.entryName)}.rels`, entry.entryName);
    const structuredTables = [...new Set(Object.values(sheetRelationships).filter(target => /^xl\/tables\/table\d+\.xml$/i.test(target)))]
      .map(file => parseExcelStructuredTable(zip, file, sheetName));
    return {
      name: sheetName,
      hidden: sheetDefinitions[index]?.state !== 'visible',
      dimension,
      cells,
      merged_cells: [...xml.matchAll(/<mergeCell\b([^>]*)\/?>/gi)].map(match => attr(match[1], 'ref')),
      rows, columns,
      freeze_pane: attr(xml.match(/<pane\b([^>]*)/i)?.[1], 'topLeftCell') || null,
      data_validations: parseExcelDataValidations(xml),
      structured_tables: structuredTables,
      what_if_data_tables: whatIfDataTables,
      hyperlinks: [...xml.matchAll(/<hyperlink\b([^>]*)/gi)].map(match => ({ ref: attr(match[1], 'ref') })),
      auto_filter: attr(xml.match(/<autoFilter\b([^>]*)/i)?.[1], 'ref') || null,
      chart_count: zip.getEntries().filter(item => /^xl\/charts\/chart\d+\.xml$/i.test(item.entryName)).length,
      image_count: zip.getEntries().filter(item => /^xl\/media\//i.test(item.entryName)).length,
    };
  });
  return {
    type: 'excel',
    workbook_name: path.basename(filePath),
    sheets,
    defined_names: definedNames,
    named_ranges: definedNames.filter(item => !item.built_in),
    structured_tables: sheets.flatMap(sheet => sheet.structured_tables || []),
    what_if_data_tables: sheets.flatMap(sheet => (sheet.what_if_data_tables || []).map(item => ({ ...item, sheet: sheet.name }))),
    parser_warnings: [],
  };
}

async function parseWord(filePath) {
  const zip = new AdmZip(filePath);
  const xml = zip.getEntry('word/document.xml')?.getData().toString('utf8') || '';
  const rawText = (await mammoth.extractRawText({ path: filePath })).value;
  const fields = parseWordFields(xml);
  const paragraphs = tags(xml, 'w:p').map((match, index) => {
    const runs = tags(match[2], 'w:r').map(run => ({
      text: tags(run[2], 'w:t').map(text => xmlText(text[2])).join(''),
      font_family: attr(run[2].match(/<w:rFonts\b([^>]*)/i)?.[1], 'w:ascii'),
      font_size: Number(attr(run[2].match(/<w:sz\b([^>]*)/i)?.[1], 'w:val') || 0) / 2 || null,
      bold: /<w:b(?:\s|\/|>)/i.test(run[2]),
      italic: /<w:i(?:\s|\/|>)/i.test(run[2]),
      underline: attr(run[2].match(/<w:u\b([^>]*)/i)?.[1], 'w:val'),
      color: attr(run[2].match(/<w:color\b([^>]*)/i)?.[1], 'w:val'),
    }));
    return {
      index,
      text: runs.map(run => run.text).join(''),
      style_name: attr(match[2].match(/<w:pStyle\b([^>]*)/i)?.[1], 'w:val'),
      heading: attr(match[2].match(/<w:outlineLvl\b([^>]*)/i)?.[1], 'w:val'),
      alignment: attr(match[2].match(/<w:jc\b([^>]*)/i)?.[1], 'w:val'),
      line_spacing: attr(match[2].match(/<w:spacing\b([^>]*)/i)?.[1], 'w:line'),
      spacing_before: attr(match[2].match(/<w:spacing\b([^>]*)/i)?.[1], 'w:before'),
      spacing_after: attr(match[2].match(/<w:spacing\b([^>]*)/i)?.[1], 'w:after'),
      indent: attr(match[2].match(/<w:ind\b([^>]*)/i)?.[1], 'w:left'),
      numbered: /<w:numPr>/i.test(match[2]),
      runs,
    };
  });
  const tableFormulas = [];
  const tables = tags(xml, 'w:tbl').map((table, tableIndex) => {
    const rowMatches = tags(table[2], 'w:tr');
    const rows = rowMatches.map((row, rowIndex) => tags(row[2], 'w:tc').map((cell, columnIndex) => {
      const cellFields = parseWordFields(cell[2]);
      for (const field of cellFields.filter(item => /^\s*=/.test(item.instruction))) {
        const instruction = field.instruction.replace(/^\s*=\s*/, '').trim();
        const expression = instruction.split(/\s+\\[#*]\s*/)[0].trim();
        tableFormulas.push({
          table_index: tableIndex,
          row: rowIndex + 1,
          column: columnIndex + 1,
          instruction: field.instruction,
          formula: expression,
          result_text: field.result_text,
        });
      }
      return tags(cell[2], 'w:t').map(text => xmlText(text[2])).join(' ').trim();
    }));
    return {
      rows: rows.length,
      columns: Math.max(0, ...rows.map(row => row.length)),
      cells: rows,
      merged: /<w:(?:gridSpan|vMerge)\b/i.test(table[2]),
      formulas: tableFormulas.filter(formula => formula.table_index === tableIndex),
    };
  });
  const tocFields = fields.filter(field => /^TOC(?:\s|$)/i.test(field.instruction));
  const tocContainerDetected = /<w:docPartGallery\b[^>]*w:val="(?:Table of Contents|Mục lục)"/i.test(xml);
  const tableOfContents = {
    exists: tocFields.length > 0,
    automatic: tocFields.length > 0,
    container_detected: tocContainerDetected,
    count: tocFields.length,
    entries: tocFields.map(field => {
      const levelMatch = field.instruction.match(/\\o\s+["']?(\d+)\s*-\s*(\d+)["']?/i);
      return {
        instruction: field.instruction,
        result_text: field.result_text,
        heading_levels: levelMatch ? { from: Number(levelMatch[1]), to: Number(levelMatch[2]) } : null,
        hyperlinks: /\\h(?:\s|$)/i.test(field.instruction),
        uses_outline_levels: /\\u(?:\s|$)/i.test(field.instruction),
      };
    }),
  };
  const contentControls = parseWordContentControls(xml);
  const legacyFormControls = parseLegacyWordFormControls(xml, fields);
  const activeXControls = zip.getEntries().filter(entry => /^word\/activeX\/activeX\d+\.xml$/i.test(entry.entryName)).map(entry => {
    const controlXml = entry.getData().toString('utf8');
    return { kind: 'activex', type: 'activex', file: entry.entryName, class_id: attr(controlXml.match(/<ocx:ocx\b([^>]*)/i)?.[1], 'ocx:classid') };
  });
  const formControls = [...contentControls, ...legacyFormControls, ...activeXControls];
  const smartart = parseWordSmartArt(zip, xml);
  const section = xml.match(/<w:sectPr[^>]*>([\s\S]*?)<\/w:sectPr>/i)?.[1] || '';
  const headers = zip.getEntries()
    .filter(entry => /^word\/header\d+\.xml$/i.test(entry.entryName))
    .map(entry => {
      const headerXml = entry.getData().toString('utf8');
      return {
        file: entry.entryName,
        text: xmlText(headerXml),
        paragraphs: tags(headerXml, 'w:p').map(item => xmlText(item[2])).filter(Boolean),
        has_fields: /<w:(?:fldChar|instrText)\b/i.test(headerXml),
        has_images: /<w:drawing\b/i.test(headerXml),
      };
    });
  const footers = zip.getEntries()
    .filter(entry => /^word\/footer\d+\.xml$/i.test(entry.entryName))
    .map(entry => {
      const footerXml = entry.getData().toString('utf8');
      return { file: entry.entryName, text: xmlText(footerXml) };
    });
  return {
    type: 'word', text: rawText.slice(0, 20000), paragraphs, tables,
    fields,
    table_of_contents: tableOfContents,
    smartart,
    form_controls: formControls,
    form_summary: {
      count: formControls.length,
      content_control_count: contentControls.length,
      legacy_control_count: legacyFormControls.length,
      activex_control_count: activeXControls.length,
      types: [...new Set(formControls.map(control => control.type))],
    },
    table_formulas: tableFormulas,
    headers: headers.map(header => header.text),
    header_details: headers,
    header_text: headers.map(header => header.text).join(' '),
    first_page_region_text: paragraphs.slice(0, 8).map(paragraph => paragraph.text).join(' '),
    footers: footers.map(footer => footer.text),
    footer_details: footers,
    all_text: [headers.map(header => header.text).join(' '), rawText, footers.map(footer => footer.text).join(' ')].join('\n').trim().slice(0, 24000),
    page_settings: {
      orientation: attr(section.match(/<w:pgSz\b([^>]*)/i)?.[1], 'w:orient') || 'portrait',
      margin_top: attr(section.match(/<w:pgMar\b([^>]*)/i)?.[1], 'w:top'),
      margin_bottom: attr(section.match(/<w:pgMar\b([^>]*)/i)?.[1], 'w:bottom'),
      margin_left: attr(section.match(/<w:pgMar\b([^>]*)/i)?.[1], 'w:left'),
      margin_right: attr(section.match(/<w:pgMar\b([^>]*)/i)?.[1], 'w:right'),
    },
    image_count: zip.getEntries().filter(entry => /^word\/media\//i.test(entry.entryName)).length,
    hyperlink_count: (xml.match(/<w:hyperlink\b/gi) || []).length,
    parser_warnings: [],
  };
}

function parsePowerPointHyperlinks(body, relationshipMap, slideNumberByFile) {
  return [...String(body || '').matchAll(/<a:hlink(Click|Hover)\b([^>]*?)(?:\/>|>([\s\S]*?)<\/a:hlink\1>)/gi)].map(match => {
    const attributes = match[2] || '';
    const relationshipId = attr(attributes, 'r:id');
    const relationship = relationshipMap.get(relationshipId) || null;
    const action = decodeXmlEntities(attr(attributes, 'action') || '');
    const targetFile = relationship?.target_mode?.toLowerCase() === 'external' ? null : relationship?.resolved_target || null;
    const targetSlide = targetFile && /^ppt\/slides\/slide\d+\.xml$/i.test(targetFile) ? slideNumberByFile.get(targetFile) || null : null;
    const navigation = action.match(/ppaction:\/\/hlinkshowjump\?jump=([^&]+)/i)?.[1] || null;
    const customShowId = action.match(/ppaction:\/\/customshow\?id=([^&]+)/i)?.[1] || null;
    return {
      trigger: match[1].toLowerCase() === 'hover' ? 'hover' : 'click',
      relationship_id: relationshipId || null,
      relationship_type: relationship?.type || null,
      target_mode: relationship?.target_mode || null,
      target: relationship?.resolved_target || null,
      target_file: targetFile,
      target_slide: targetSlide,
      action: action || null,
      navigation,
      custom_show_id: customShowId,
      tooltip: decodeXmlEntities(attr(attributes, 'tooltip') || ''),
      link_type: targetSlide ? 'internal_slide' : (navigation ? 'slide_navigation' : (customShowId ? 'custom_show' : (relationship?.target_mode?.toLowerCase() === 'external' ? 'external' : 'action'))),
    };
  });
}

function parsePowerPointObjects(xml, relationshipDetailsList = [], slideNumberByFile = new Map()) {
  const relationshipMap = new Map(relationshipDetailsList.map(item => [item.id, item]));
  return [...String(xml || '').matchAll(/<p:(sp|pic|graphicFrame)\b[^>]*>([\s\S]*?)<\/p:\1>/gi)].map(match => {
    const body = match[2];
    const offset = body.match(/<a:off\b([^>]*)/i)?.[1] || '';
    const extent = body.match(/<a:ext\b([^>]*)/i)?.[1] || '';
    const text = tags(body, 'a:t').map(item => xmlText(item[2])).join(' ');
    const runProperties = body.match(/<a:rPr\b([^>]*)/i)?.[1] || body.match(/<a:defRPr\b([^>]*)/i)?.[1] || '';
    const nonVisual = body.match(/<p:cNvPr\b([^>]*)/i)?.[1] || '';
    const placeholderAttributes = body.match(/<p:ph\b([^>]*)\/?\s*>/i)?.[1] || '';
    const hyperlinks = parsePowerPointHyperlinks(body, relationshipMap, slideNumberByFile);
    return {
      id: attr(nonVisual, 'id') || null,
      name: decodeXmlEntities(attr(nonVisual, 'name') || ''),
      type: match[1] === 'pic' ? 'image' : (/<a:tbl\b/i.test(body) ? 'table' : (/<c:chart\b/i.test(body) ? 'chart' : (text ? 'text' : 'shape'))),
      text,
      placeholder: placeholderAttributes ? {
        type: attr(placeholderAttributes, 'type') || 'body',
        index: attr(placeholderAttributes, 'idx') || null,
        size: attr(placeholderAttributes, 'sz') || null,
        orientation: attr(placeholderAttributes, 'orient') || null,
      } : null,
      position: { x: emuToInch(attr(offset, 'x')), y: emuToInch(attr(offset, 'y')), width: emuToInch(attr(extent, 'cx')), height: emuToInch(attr(extent, 'cy')) },
      rotation: Number(attr(body.match(/<a:xfrm\b([^>]*)/i)?.[1], 'rot') || 0) / 60000,
      formatting: {
        font_family: attr(body.match(/<a:latin\b([^>]*)/i)?.[1], 'typeface'),
        font_size: Number(attr(runProperties, 'sz') || 0) / 100 || null,
        bold: attr(runProperties, 'b') === '1',
        italic: attr(runProperties, 'i') === '1',
        font_color: attr(body.match(/<a:srgbClr\b([^>]*)/i)?.[1], 'val'),
        alignment: attr(body.match(/<a:pPr\b([^>]*)/i)?.[1], 'algn'),
      },
      hyperlinks,
    };
  });
}

function parsePowerPointMasterTextStyles(masterXml) {
  return Object.fromEntries(['title', 'body', 'other'].map(styleName => {
    const styleXml = tag(masterXml, `p:${styleName}Style`);
    const levels = [...styleXml.matchAll(/<a:lvl(\d+)pPr\b([^>]*)>([\s\S]*?)<\/a:lvl\1pPr>/gi)].map(match => {
      const defaultRun = match[3].match(/<a:defRPr\b([^>]*)/i)?.[1] || '';
      return {
        level: Number(match[1]),
        alignment: attr(match[2], 'algn') || null,
        margin_left: attr(match[2], 'marL') || null,
        indent: attr(match[2], 'indent') || null,
        font_size: Number(attr(defaultRun, 'sz') || 0) / 100 || null,
        bold: attr(defaultRun, 'b') === '1',
        font_family: attr(match[3].match(/<a:latin\b([^>]*)/i)?.[1], 'typeface'),
      };
    });
    return [styleName, levels];
  }));
}

function parsePowerPointSlideMasters(zip, slideNumberByFile) {
  return zip.getEntries()
    .filter(entry => /^ppt\/slideMasters\/slideMaster\d+\.xml$/i.test(entry.entryName))
    .sort((a, b) => a.entryName.localeCompare(b.entryName, undefined, { numeric: true }))
    .map((entry, index) => {
      const xml = entry.getData().toString('utf8');
      const relationships = relationshipDetails(zip, `ppt/slideMasters/_rels/${path.basename(entry.entryName)}.rels`, entry.entryName);
      const objects = parsePowerPointObjects(xml, relationships, slideNumberByFile);
      const layoutFiles = relationships.map(item => item.resolved_target).filter(target => /^ppt\/slideLayouts\/slideLayout\d+\.xml$/i.test(target));
      const layouts = layoutFiles.map(file => {
        const layoutXml = zip.getEntry(file)?.getData().toString('utf8') || '';
        const layoutAttributes = layoutXml.match(/<p:sldLayout\b([^>]*)/i)?.[1] || '';
        return {
          file,
          name: decodeXmlEntities(attr(layoutXml.match(/<p:cSld\b([^>]*)/i)?.[1], 'name') || attr(layoutAttributes, 'matchingName') || ''),
          type: attr(layoutAttributes, 'type') || null,
          show_master_shapes: attr(layoutAttributes, 'showMasterSp') !== '0',
        };
      });
      const themeFile = relationships.map(item => item.resolved_target).find(target => /^ppt\/theme\/theme\d+\.xml$/i.test(target)) || null;
      const themeXml = themeFile ? (zip.getEntry(themeFile)?.getData().toString('utf8') || '') : '';
      const headerFooterMatch = xml.match(/<p:hf\b([^>]*)\/?\s*>/i);
      const headerFooterAttributes = headerFooterMatch?.[1] || '';
      return {
        number: index + 1,
        file: entry.entryName,
        name: decodeXmlEntities(attr(xml.match(/<p:cSld\b([^>]*)/i)?.[1], 'name') || attr(xml.match(/<p:sldMaster\b([^>]*)/i)?.[1], 'name') || `Slide Master ${index + 1}`),
        preserve: xmlBoolean(attr(xml.match(/<p:sldMaster\b([^>]*)/i)?.[1], 'preserve')),
        background: /<p:bg\b/i.test(xml),
        objects,
        object_count: objects.length,
        placeholders: objects.filter(object => object.placeholder).map(object => ({ object_id: object.id, name: object.name, ...object.placeholder })),
        layouts,
        theme: {
          file: themeFile,
          name: decodeXmlEntities(attr(themeXml.match(/<a:theme\b([^>]*)/i)?.[1], 'name') || ''),
          color_scheme: decodeXmlEntities(attr(themeXml.match(/<a:clrScheme\b([^>]*)/i)?.[1], 'name') || ''),
        },
        header_footer: {
          detected: Boolean(headerFooterMatch),
          date_time: Boolean(headerFooterMatch) && attr(headerFooterAttributes, 'dt') !== '0',
          footer: Boolean(headerFooterMatch) && attr(headerFooterAttributes, 'ftr') !== '0',
          slide_number: Boolean(headerFooterMatch) && attr(headerFooterAttributes, 'sldNum') !== '0',
          header: Boolean(headerFooterMatch) && attr(headerFooterAttributes, 'hdr') !== '0',
        },
        text_styles: parsePowerPointMasterTextStyles(xml),
      };
    });
}

function parsePowerPoint(filePath) {
  const zip = new AdmZip(filePath);
  const presentation = zip.getEntry('ppt/presentation.xml')?.getData().toString('utf8') || '';
  const sizeAttrs = presentation.match(/<p:sldSz\b([^>]*)/i)?.[1] || '';
  const theme = presentationThemeDetails(zip);
  const presentationRelationships = relationshipDetails(zip, 'ppt/_rels/presentation.xml.rels', 'ppt/presentation.xml');
  const presentationRelationshipMap = new Map(presentationRelationships.map(item => [item.id, item]));
  const slideRelationshipIds = [...presentation.matchAll(/<p:sldId\b([^>]*)\/?\s*>/gi)].map(match => attr(match[1], 'r:id')).filter(Boolean);
  const orderedSlideFiles = slideRelationshipIds.map(id => presentationRelationshipMap.get(id)?.resolved_target).filter(target => /^ppt\/slides\/slide\d+\.xml$/i.test(target));
  const discoveredSlideFiles = zip.getEntries().filter(entry => /^ppt\/slides\/slide\d+\.xml$/i.test(entry.entryName)).map(entry => entry.entryName).sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
  const slideFiles = orderedSlideFiles.length
    ? [...orderedSlideFiles, ...discoveredSlideFiles.filter(file => !orderedSlideFiles.includes(file))]
    : discoveredSlideFiles;
  const slideEntries = slideFiles.map(file => zip.getEntry(file)).filter(Boolean);
  const slideNumberByFile = new Map(slideFiles.map((file, index) => [file, index + 1]));
  const slideMasters = parsePowerPointSlideMasters(zip, slideNumberByFile);
  const masterByFile = new Map(slideMasters.map(master => [master.file, master]));
  const customSlideShows = tags(tag(presentation, 'p:custShowLst'), 'p:custShow').map(match => {
    const slideShowFiles = [...match[2].matchAll(/<p:sld\b([^>]*)\/?\s*>/gi)]
      .map(item => presentationRelationshipMap.get(attr(item[1], 'r:id'))?.resolved_target)
      .filter(Boolean);
    return {
      name: decodeXmlEntities(attr(match[1], 'name') || ''),
      id: attr(match[1], 'id') || null,
      slide_files: slideShowFiles,
      slides: slideShowFiles.map(file => slideNumberByFile.get(file)).filter(Boolean),
      slide_count: slideShowFiles.length,
    };
  });
  const slides = slideEntries.map((entry, index) => {
    const xml = entry.getData().toString('utf8');
    const relationshipFile = `ppt/slides/_rels/${path.basename(entry.entryName)}.rels`;
    const relationshipDetailsList = relationshipDetails(zip, relationshipFile, entry.entryName);
    const relationships = Object.fromEntries(relationshipDetailsList.map(item => [item.id, item.resolved_target]));
    const layoutFile = Object.values(relationships).find(target => /^ppt\/slideLayouts\/slideLayout\d+\.xml$/i.test(target));
    const layoutXml = layoutFile ? (zip.getEntry(layoutFile)?.getData().toString('utf8') || '') : '';
    const layoutRelationships = layoutFile
      ? relationshipTargets(zip, `ppt/slideLayouts/_rels/${path.basename(layoutFile)}.rels`, layoutFile)
      : {};
    const masterFile = Object.values(layoutRelationships).find(target => /^ppt\/slideMasters\/slideMaster\d+\.xml$/i.test(target));
    const masterXml = masterFile ? (zip.getEntry(masterFile)?.getData().toString('utf8') || '') : '';
    const hasOwnBackground = /<p:bg\b/i.test(xml);
    const hasLayoutBackground = /<p:bg\b/i.test(layoutXml);
    const hasMasterBackground = /<p:bg\b/i.test(masterXml);
    const objects = parsePowerPointObjects(xml, relationshipDetailsList, slideNumberByFile);
    const transitionMatch = xml.match(/<p:transition\b([^>]*)>([\s\S]*?)<\/p:transition>|<p:transition\b([^>]*)\/>/i);
    const transitionAttrs = transitionMatch?.[1] || transitionMatch?.[3] || '';
    const transitionBody = transitionMatch?.[2] || '';
    const transitionType = transitionBody.match(/<p:([a-zA-Z0-9]+)\b/i)?.[1] || (transitionMatch ? 'default' : null);
    const transition = transitionMatch ? {
      exists: true,
      type: transitionType,
      speed: attr(transitionAttrs, 'spd') || null,
      advance_on_click: attr(transitionAttrs, 'advClick') !== '0',
      advance_after_ms: attr(transitionAttrs, 'advTm') !== '' ? Number(attr(transitionAttrs, 'advTm')) : null,
      sound: /<p:sndAc\b/i.test(transitionBody),
      raw_attributes: transitionAttrs,
    } : {
      exists: false,
      type: null,
      speed: null,
      advance_on_click: true,
      advance_after_ms: null,
      sound: false,
    };
    const animationTags = ['animEffect', 'animMotion', 'animRot', 'animScale', 'anim', 'set', 'cmd'];
    const animations = [];
    for (const tagName of animationTags) {
      const pattern = new RegExp(`<p:${tagName}\\b([^>]*)>([\\s\\S]*?)<\\/p:${tagName}>`, 'gi');
      for (const animationMatch of xml.matchAll(pattern)) {
        const body = animationMatch[2];
        const targetId = attr(body.match(/<p:spTgt\b([^>]*)/i)?.[1], 'spid') || null;
        const targetObject = objects.find(object => String(object.id) === String(targetId));
        const commonTime = body.match(/<p:cTn\b([^>]*)/i)?.[1] || '';
        const delayValue = attr(body.match(/<p:cond\b([^>]*)/i)?.[1], 'delay');
        animations.push({
          type: tagName,
          target_shape_id: targetId,
          target_name: targetObject?.name || '',
          target_text: targetObject?.text || '',
          effect: attr(animationMatch[1], 'filter') || attr(animationMatch[1], 'path') || attr(animationMatch[1], 'calcmode') || tagName,
          transition: attr(animationMatch[1], 'transition') || null,
          duration_ms: /^\d+$/.test(attr(commonTime, 'dur')) ? Number(attr(commonTime, 'dur')) : attr(commonTime, 'dur') || null,
          delay_ms: /^\d+$/.test(delayValue) ? Number(delayValue) : delayValue || null,
          auto_reverse: attr(commonTime, 'autoRev') === '1',
          repeat_count: attr(commonTime, 'repeatCount') || null,
        });
      }
    }
    const timingPresent = /<p:timing\b/i.test(xml);
    const notesFile = Object.values(relationships).find(target => /^ppt\/notesSlides\/notesSlide\d+\.xml$/i.test(target));
    const notesEntry = notesFile ? zip.getEntry(notesFile) : null;
    const hyperlinks = objects.flatMap(object => object.hyperlinks.map(link => ({ ...link, object_id: object.id, object_name: object.name, object_text: object.text })));
    const parsedMaster = masterByFile.get(masterFile);
    return {
      number: index + 1, file: entry.entryName, objects,
      title: objects.find(object => object.type === 'text' && object.text)?.text || '',
      background: {
        status: hasOwnBackground || hasLayoutBackground || hasMasterBackground ? 'detected' : 'not_explicit',
        source: hasOwnBackground ? 'slide' : (hasLayoutBackground ? 'layout' : (hasMasterBackground ? 'master' : 'theme_or_default')),
        slide_background: hasOwnBackground,
        layout_background: hasLayoutBackground,
        master_background: hasMasterBackground,
      },
      notes: notesEntry ? xmlText(notesEntry.getData().toString('utf8')) : '',
      layout: {
        status: layoutFile ? 'detected' : 'not_found',
        file: layoutFile || null,
        name: decodeXmlEntities(attr(layoutXml.match(/<p:cSld\b([^>]*)/i)?.[1], 'name') || attr(layoutXml.match(/<p:sldLayout\b([^>]*)/i)?.[1], 'matchingName') || attr(layoutXml.match(/<p:sldLayout\b([^>]*)/i)?.[1], 'type') || ''),
        master_file: masterFile || null,
        master_name: parsedMaster?.name || decodeXmlEntities(attr(masterXml.match(/<p:cSld\b([^>]*)/i)?.[1], 'name') || attr(masterXml.match(/<p:sldMaster\b([^>]*)/i)?.[1], 'name') || ''),
      },
      hyperlinks,
      internal_hyperlinks: hyperlinks.filter(link => ['internal_slide', 'slide_navigation', 'custom_show'].includes(link.link_type)),
      transition,
      animations,
      animation_summary: {
        timing_present: timingPresent,
        animated_object_count: new Set(animations.map(animation => animation.target_shape_id).filter(Boolean)).size,
        effect_count: animations.length,
        has_effects: timingPresent || animations.length > 0,
      },
    };
  });
  return {
    type: 'powerpoint',
    slide_size: { width: emuToInch(attr(sizeAttrs, 'cx')), height: emuToInch(attr(sizeAttrs, 'cy')) },
    theme,
    slide_masters: slideMasters,
    custom_slide_shows: customSlideShows,
    slides,
    internal_hyperlinks: slides.flatMap(slide => slide.internal_hyperlinks.map(link => ({ ...link, source_slide: slide.number }))),
    parser_warnings: slides.some(slide => slide.animation_summary.timing_present && !slide.animations.length)
      ? ['Có timing PowerPoint nhưng một số hiệu ứng nâng cao không ánh xạ được tới đối tượng; chỉ các mục này cần giáo viên kiểm tra.']
      : [],
  };
}

export async function extractStructuredDocument(filePath) {
  const extension = path.extname(filePath).toLowerCase();
  if (extension === '.xlsx') return parseExcel(filePath);
  if (extension === '.docx') return parseWord(filePath);
  if (extension === '.pptx') return parsePowerPoint(filePath);
  return { type: extension.slice(1) || 'unknown', text: fs.readFileSync(filePath, 'utf8').slice(0, 20000), parser_warnings: ['Định dạng chỉ hỗ trợ trích xuất text.'] };
}

function similarity(left, right) {
  const a = new Set(String(left || '').toLowerCase().split(/\s+/).filter(Boolean));
  const b = new Set(String(right || '').toLowerCase().split(/\s+/).filter(Boolean));
  if (!a.size && !b.size) return 1;
  const intersection = [...a].filter(value => b.has(value)).length;
  return Math.round((intersection / Math.max(1, a.size + b.size - intersection)) * 1000) / 1000;
}

export function createDocumentFingerprint(filePath, document) {
  const buffer = fs.readFileSync(filePath);
  const text = document.text || document.paragraphs?.map(item => item.text).join('\n') || document.sheets?.flatMap(sheet => Object.values(sheet.cells).map(cell => cell.value)).join(' ') || document.slides?.flatMap(slide => slide.objects.map(object => object.text)).join(' ') || '';
  const structure = {
    type: document.type,
    paragraphs: document.paragraphs?.length || 0,
    tables: document.tables?.length || 0,
    sheets: document.sheets?.map(sheet => ({
      name: sheet.name,
      dimension: sheet.dimension,
      formulas: Object.values(sheet.cells).filter(cell => cell.formula).map(cell => `${cell.address}:${cell.formula}`),
      data_validations: sheet.data_validations || [],
      structured_tables: sheet.structured_tables || [],
      what_if_data_tables: sheet.what_if_data_tables || [],
    })) || [],
    named_ranges: document.named_ranges || [],
    slides: document.slides?.map(slide => ({ objects: slide.objects.map(object => object.type), internal_hyperlinks: slide.internal_hyperlinks || [] })) || [],
    slide_masters: document.slide_masters || [],
    custom_slide_shows: document.custom_slide_shows || [],
    images: document.image_count || document.sheets?.reduce((sum, sheet) => sum + sheet.image_count, 0) || 0,
  };
  return { file_sha256: hash(buffer), text_sha256: hash(text), structure_sha256: hash(JSON.stringify(structure)), file_size: buffer.length, text, structure };
}

export function compareFingerprints(reference, submission) {
  const identical = reference.file_sha256 === submission.file_sha256;
  const textSimilarity = similarity(reference.text, submission.text);
  const structuralSimilarity = reference.structure_sha256 === submission.structure_sha256 ? 1 : similarity(JSON.stringify(reference.structure), JSON.stringify(submission.structure));
  const suspicious = identical || (textSimilarity >= 0.98 && structuralSimilarity >= 0.97);
  return {
    identical_file_hash: identical,
    text_similarity: textSimilarity,
    structural_similarity: structuralSimilarity,
    meaningful_changes: identical ? [] : ['File có thay đổi so với mẫu; xem evidence từng tiêu chí để xác định thay đổi hợp lệ.'],
    suspicious_submission: suspicious,
    reasons: identical ? ['Bài nộp giống hệt file mẫu'] : (suspicious ? ['Bài nộp gần như giống file mẫu và cần giáo viên kiểm tra'] : []),
  };
}
