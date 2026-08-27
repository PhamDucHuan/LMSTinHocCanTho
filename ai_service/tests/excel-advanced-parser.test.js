import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import AdmZip from 'adm-zip';
import { extractStructuredDocument } from '../lib/document-tools.js';

function addText(zip, name, content) {
  zip.addFile(name, Buffer.from(content, 'utf8'));
}

function createAdvancedExcelFixture(filePath) {
  const zip = new AdmZip();
  addText(zip, '[Content_Types].xml', `<?xml version="1.0" encoding="UTF-8"?>
    <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
      <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
      <Default Extension="xml" ContentType="application/xml"/>
      <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
      <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
      <Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>
    </Types>`);
  addText(zip, 'xl/workbook.xml', `<?xml version="1.0" encoding="UTF-8"?>
    <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
      <sheets><sheet name="DuLieu" sheetId="1" r:id="rId1"/></sheets>
      <definedNames>
        <definedName name="DanhSach">'DuLieu'!$A$2:$A$5</definedName>
        <definedName name="CucBo" localSheetId="0">'DuLieu'!$B$2:$B$5</definedName>
        <definedName name="_xlnm.Print_Area" localSheetId="0">'DuLieu'!$A$1:$E$10</definedName>
      </definedNames>
    </workbook>`);
  addText(zip, 'xl/_rels/workbook.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    </Relationships>`);
  addText(zip, 'xl/worksheets/sheet1.xml', `<?xml version="1.0" encoding="UTF-8"?>
    <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
      <dimension ref="A1:E10"/>
      <sheetData>
        <row r="1"><c r="A1" t="inlineStr"><is><t>Tên</t></is></c></row>
        <row r="2"><c r="A2" t="inlineStr"><is><t>An</t></is></c><c r="D2"><f t="dataTable" ref="D2:E6" dt2D="1" r1="$B$1" r2="$B$2"/><v>100</v></c></row>
      </sheetData>
      <dataValidations count="1"><dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" errorStyle="stop" promptTitle="Chọn" prompt="Chọn trạng thái" errorTitle="Sai" error="Giá trị không hợp lệ" sqref="B2:B10"><formula1>&quot;Có,Không&quot;</formula1></dataValidation></dataValidations>
      <tableParts count="1"><tablePart r:id="rIdTable"/></tableParts>
    </worksheet>`);
  addText(zip, 'xl/worksheets/_rels/sheet1.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rIdTable" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>
    </Relationships>`);
  addText(zip, 'xl/tables/table1.xml', `<?xml version="1.0" encoding="UTF-8"?>
    <table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="BangDuLieu" displayName="BangDuLieu" ref="A1:C5" totalsRowCount="1">
      <autoFilter ref="A1:C4"/>
      <tableColumns count="3">
        <tableColumn id="1" name="Tên"/>
        <tableColumn id="2" name="Số lượng"/>
        <tableColumn id="3" name="Thành tiền" totalsRowFunction="sum"><calculatedColumnFormula>[@[Số lượng]]*10</calculatedColumnFormula></tableColumn>
      </tableColumns>
      <tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>
    </table>`);
  zip.writeZip(filePath);
}

test('Excel parser exposes Named Ranges, detailed validation, structured tables and What-If Data Tables', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'lms-excel-advanced-'));
  const filePath = path.join(directory, 'advanced.xlsx');
  try {
    createAdvancedExcelFixture(filePath);
    const document = await extractStructuredDocument(filePath);
    assert.equal(document.named_ranges.length, 2);
    assert.deepEqual(document.named_ranges[0], {
      name: 'DanhSach', refers_to: "'DuLieu'!$A$2:$A$5", normalized_reference: 'A2:A5', referenced_sheet: 'DuLieu',
      scope: 'workbook', scope_sheet: null, local_sheet_id: null, hidden: false, comment: '', built_in: false, kind: 'range',
    });
    assert.equal(document.named_ranges[1].scope, 'worksheet');
    assert.equal(document.named_ranges[1].scope_sheet, 'DuLieu');
    assert.deepEqual(document.sheets[0].data_validations[0], {
      range: 'B2:B10', type: 'list', operator: null, formula1: '"Có,Không"', formula2: null,
      allow_blank: true, show_dropdown_attribute: null, dropdown_arrow_visible: null,
      show_input_message: true, show_error_message: true, error_style: 'stop', prompt_title: 'Chọn', prompt: 'Chọn trạng thái',
      error_title: 'Sai', error: 'Giá trị không hợp lệ', ime_mode: null, source: 'dataValidation',
    });
    assert.equal(document.structured_tables[0].display_name, 'BangDuLieu');
    assert.equal(document.structured_tables[0].range, 'A1:C5');
    assert.equal(document.structured_tables[0].columns[2].calculated_column_formula, '[@[Số lượng]]*10');
    assert.equal(document.what_if_data_tables[0].range, 'D2:E6');
    assert.equal(document.what_if_data_tables[0].two_variable, true);
    assert.equal(document.what_if_data_tables[0].row_input_cell, '$B$1');
    assert.equal(document.what_if_data_tables[0].column_input_cell, '$B$2');
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
