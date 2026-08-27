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

function createAdvancedWordFixture(filePath) {
  const zip = new AdmZip();
  addText(zip, '[Content_Types].xml', `<?xml version="1.0" encoding="UTF-8"?>
    <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
      <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
      <Default Extension="xml" ContentType="application/xml"/>
      <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    </Types>`);
  addText(zip, '_rels/.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
    </Relationships>`);
  addText(zip, 'word/_rels/document.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rIdData" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/diagramData" Target="diagrams/data1.xml"/>
      <Relationship Id="rIdLayout" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/diagramLayout" Target="diagrams/layout1.xml"/>
      <Relationship Id="rIdStyle" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/diagramQuickStyle" Target="diagrams/quickStyle1.xml"/>
      <Relationship Id="rIdColors" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/diagramColors" Target="diagrams/colors1.xml"/>
    </Relationships>`);
  addText(zip, 'word/document.xml', `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
      xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"
      xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
      xmlns:dgm="http://schemas.openxmlformats.org/drawingml/2006/diagram">
      <w:body>
        <w:sdt>
          <w:sdtPr><w:docPartObj><w:docPartGallery w:val="Table of Contents"/></w:docPartObj></w:sdtPr>
          <w:sdtContent><w:p>
            <w:r><w:fldChar w:fldCharType="begin"/></w:r>
            <w:r><w:instrText xml:space="preserve"> TOC \\o &quot;1-3&quot; \\h \\u </w:instrText></w:r>
            <w:r><w:fldChar w:fldCharType="separate"/></w:r>
            <w:r><w:t>Mục 1 1</w:t></w:r>
            <w:r><w:fldChar w:fldCharType="end"/></w:r>
          </w:p></w:sdtContent>
        </w:sdt>
        <w:p><w:r><w:drawing><dgm:relIds r:dm="rIdData" r:lo="rIdLayout" r:qs="rIdStyle" r:cs="rIdColors"/></w:drawing></w:r></w:p>
        <w:sdt>
          <w:sdtPr><w:id w:val="7"/><w:alias w:val="Đồng ý"/><w:tag w:val="consent"/><w14:checkbox><w14:checked w14:val="1"/></w14:checkbox></w:sdtPr>
          <w:sdtContent><w:r><w:t>Đã đồng ý</w:t></w:r></w:sdtContent>
        </w:sdt>
        <w:tbl><w:tr><w:tc><w:p>
          <w:r><w:fldChar w:fldCharType="begin"/></w:r>
          <w:r><w:instrText xml:space="preserve"> =SUM(ABOVE) \\# &quot;0&quot; </w:instrText></w:r>
          <w:r><w:fldChar w:fldCharType="separate"/></w:r>
          <w:r><w:t>42</w:t></w:r>
          <w:r><w:fldChar w:fldCharType="end"/></w:r>
        </w:p></w:tc></w:tr></w:tbl>
        <w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr>
      </w:body>
    </w:document>`);
  addText(zip, 'word/diagrams/data1.xml', `<?xml version="1.0" encoding="UTF-8"?><dgm:dataModel xmlns:dgm="http://schemas.openxmlformats.org/drawingml/2006/diagram" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><dgm:ptLst><dgm:pt><dgm:t><a:p><a:r><a:t>Quy trình xử lý</a:t></a:r></a:p></dgm:t></dgm:pt></dgm:ptLst></dgm:dataModel>`);
  addText(zip, 'word/diagrams/layout1.xml', `<?xml version="1.0" encoding="UTF-8"?><dgm:layoutDef xmlns:dgm="http://schemas.openxmlformats.org/drawingml/2006/diagram" uniqueId="urn:test:process"/>`);
  addText(zip, 'word/diagrams/quickStyle1.xml', '<dgm:styleDef xmlns:dgm="http://schemas.openxmlformats.org/drawingml/2006/diagram"/>');
  addText(zip, 'word/diagrams/colors1.xml', '<dgm:colorsDef xmlns:dgm="http://schemas.openxmlformats.org/drawingml/2006/diagram"/>');
  zip.writeZip(filePath);
}

test('Word parser exposes automatic TOC, SmartArt, form controls and table formulas', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'lms-word-advanced-'));
  const filePath = path.join(directory, 'advanced.docx');
  try {
    createAdvancedWordFixture(filePath);
    const document = await extractStructuredDocument(filePath);
    assert.equal(document.table_of_contents.automatic, true);
    assert.deepEqual(document.table_of_contents.entries[0].heading_levels, { from: 1, to: 3 });
    assert.equal(document.smartart.count, 1);
    assert.deepEqual(document.smartart.diagrams[0].text, ['Quy trình xử lý']);
    assert.equal(document.form_summary.count, 1);
    assert.deepEqual(document.form_controls[0], {
      kind: 'content_control', type: 'checkbox', id: '7', title: 'Đồng ý', tag: 'consent', checked: true, content: 'Đã đồng ý',
    });
    assert.deepEqual(document.table_formulas[0], {
      table_index: 0, row: 1, column: 1, instruction: '=SUM(ABOVE) \\# "0"', formula: 'SUM(ABOVE)', result_text: '42',
    });
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
