import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import AdmZip from 'adm-zip';
import mammoth from 'mammoth';

const emuToInch = value => Math.round((Number(value || 0) / 914400) * 1000) / 1000;
const xmlText = value => String(value || '').replace(/<[^>]+>/g, ' ').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>').replace(/\s+/g, ' ').trim();
const attr = (value, name) => String(value || '').match(new RegExp(`\\b${name}="([^"]*)"`, 'i'))?.[1] ?? null;
const tag = (value, name) => String(value || '').match(new RegExp(`<${name}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${name}>`, 'i'))?.[1] ?? '';
const tags = (value, name) => [...String(value || '').matchAll(new RegExp(`<${name}(?:\\s([^>]*))?>([\\s\\S]*?)<\\/${name}>`, 'gi'))];
const hash = value => crypto.createHash('sha256').update(value).digest('hex');

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

function parseExcel(filePath) {
  const zip = new AdmZip(filePath);
  const sharedEntry = zip.getEntry('xl/sharedStrings.xml');
  const shared = sharedEntry ? tags(sharedEntry.getData().toString('utf8'), 'si').map(match => xmlText(match[2])) : [];
  const styles = parseExcelStyles(zip);
  const workbookXml = zip.getEntry('xl/workbook.xml')?.getData().toString('utf8') || '';
  const sheetDefinitions = [...workbookXml.matchAll(/<sheet\b([^>]*)\/?>/gi)].map(match => ({
    name: attr(match[1], 'name'),
    state: attr(match[1], 'state') || 'visible',
  }));
  const sheetEntries = zip.getEntries().filter(entry => /^xl\/worksheets\/sheet\d+\.xml$/i.test(entry.entryName)).sort((a, b) => a.entryName.localeCompare(b.entryName, undefined, { numeric: true }));
  const sheets = sheetEntries.map((entry, index) => {
    const xml = entry.getData().toString('utf8');
    const cells = {};
    for (const match of xml.matchAll(/<c\b([^>]*)>([\s\S]*?)<\/c>/gi)) {
      const address = attr(match[1], 'r');
      if (!address) continue;
      const type = attr(match[1], 't') || 'number';
      const formula = xmlText(tag(match[2], 'f')) || null;
      let value = xmlText(tag(match[2], type === 'inlineStr' ? 'is' : 'v'));
      if (type === 's') value = shared[Number(value)] ?? value;
      cells[address] = {
        address, value, data_type: type, formula,
        cached_result: formula ? value : null,
        number_format: styles[Number(attr(match[1], 's') || 0)]?.number_format ?? null,
        style: styles[Number(attr(match[1], 's') || 0)] || {},
        hyperlink: null,
      };
    }
    const dimension = attr(xml.match(/<dimension\b([^>]*)/i)?.[1], 'ref') || '';
    const rows = [...xml.matchAll(/<row\b([^>]*)/gi)].map(match => ({ index: Number(attr(match[1], 'r')), height: Number(attr(match[1], 'ht')) || null, hidden: attr(match[1], 'hidden') === '1' }));
    const columns = [...xml.matchAll(/<col\b([^>]*)\/?>/gi)].map(match => ({ min: Number(attr(match[1], 'min')), max: Number(attr(match[1], 'max')), width: Number(attr(match[1], 'width')) || null, hidden: attr(match[1], 'hidden') === '1' }));
    return {
      name: sheetDefinitions[index]?.name || `Sheet${index + 1}`,
      hidden: sheetDefinitions[index]?.state !== 'visible',
      dimension,
      cells,
      merged_cells: [...xml.matchAll(/<mergeCell\b([^>]*)\/?>/gi)].map(match => attr(match[1], 'ref')),
      rows, columns,
      freeze_pane: attr(xml.match(/<pane\b([^>]*)/i)?.[1], 'topLeftCell') || null,
      data_validations: [...xml.matchAll(/<dataValidation\b([^>]*)/gi)].map(match => ({ range: attr(match[1], 'sqref'), type: attr(match[1], 'type') })),
      hyperlinks: [...xml.matchAll(/<hyperlink\b([^>]*)/gi)].map(match => ({ ref: attr(match[1], 'ref') })),
      auto_filter: attr(xml.match(/<autoFilter\b([^>]*)/i)?.[1], 'ref') || null,
      chart_count: zip.getEntries().filter(item => /^xl\/charts\/chart\d+\.xml$/i.test(item.entryName)).length,
      image_count: zip.getEntries().filter(item => /^xl\/media\//i.test(item.entryName)).length,
    };
  });
  return { type: 'excel', workbook_name: path.basename(filePath), sheets, parser_warnings: [] };
}

async function parseWord(filePath) {
  const zip = new AdmZip(filePath);
  const xml = zip.getEntry('word/document.xml')?.getData().toString('utf8') || '';
  const rawText = (await mammoth.extractRawText({ path: filePath })).value;
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
  const tables = tags(xml, 'w:tbl').map(table => {
    const rows = tags(table[2], 'w:tr').map(row => tags(row[2], 'w:tc').map(cell => xmlText(cell[2])));
    return { rows: rows.length, columns: Math.max(0, ...rows.map(row => row.length)), cells: rows, merged: /<w:(?:gridSpan|vMerge)\b/i.test(table[2]) };
  });
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

function parsePowerPoint(filePath) {
  const zip = new AdmZip(filePath);
  const presentation = zip.getEntry('ppt/presentation.xml')?.getData().toString('utf8') || '';
  const sizeAttrs = presentation.match(/<p:sldSz\b([^>]*)/i)?.[1] || '';
  const slideEntries = zip.getEntries().filter(entry => /^ppt\/slides\/slide\d+\.xml$/i.test(entry.entryName)).sort((a, b) => a.entryName.localeCompare(b.entryName, undefined, { numeric: true }));
  const slides = slideEntries.map((entry, index) => {
    const xml = entry.getData().toString('utf8');
    const objects = [...xml.matchAll(/<p:(sp|pic|graphicFrame)\b[^>]*>([\s\S]*?)<\/p:\1>/gi)].map(match => {
      const body = match[2];
      const offset = body.match(/<a:off\b([^>]*)/i)?.[1] || '';
      const extent = body.match(/<a:ext\b([^>]*)/i)?.[1] || '';
      const text = tags(body, 'a:t').map(item => xmlText(item[2])).join(' ');
      const runProperties = body.match(/<a:rPr\b([^>]*)/i)?.[1] || '';
      const nonVisual = body.match(/<p:cNvPr\b([^>]*)/i)?.[1] || '';
      return {
        id: attr(nonVisual, 'id') || null,
        name: attr(nonVisual, 'name') || '',
        type: match[1] === 'pic' ? 'image' : (/<a:tbl\b/i.test(body) ? 'table' : (/<c:chart\b/i.test(body) ? 'chart' : (text ? 'text' : 'shape'))),
        text,
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
      };
    });
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
    const notesEntry = zip.getEntry(`ppt/notesSlides/notesSlide${index + 1}.xml`);
    return {
      number: index + 1, objects,
      title: objects.find(object => object.type === 'text' && object.text)?.text || '',
      background: /<p:bg\b/i.test(xml) ? { status: 'detected' } : { status: 'unsupported' },
      notes: notesEntry ? xmlText(notesEntry.getData().toString('utf8')) : '',
      layout: { status: 'unsupported' },
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
    slides,
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
    sheets: document.sheets?.map(sheet => ({ name: sheet.name, dimension: sheet.dimension, formulas: Object.values(sheet.cells).filter(cell => cell.formula).map(cell => `${cell.address}:${cell.formula}`) })) || [],
    slides: document.slides?.map(slide => slide.objects.map(object => object.type)) || [],
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
