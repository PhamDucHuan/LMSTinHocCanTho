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

function slideXml(text, hyperlink = '') {
  return `<?xml version="1.0" encoding="UTF-8"?>
    <p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
      <p:cSld><p:spTree><p:sp><p:nvSpPr><p:cNvPr id="2" name="Nút chi tiết">${hyperlink}</p:cNvPr><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="914400" y="914400"/><a:ext cx="2743200" cy="685800"/></a:xfrm></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr sz="2400"/><a:t>${text}</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld>
    </p:sld>`;
}

function createAdvancedPowerPointFixture(filePath) {
  const zip = new AdmZip();
  addText(zip, 'ppt/presentation.xml', `<?xml version="1.0" encoding="UTF-8"?>
    <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
      <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster"/></p:sldMasterIdLst>
      <p:sldIdLst><p:sldId id="256" r:id="rIdSlide2"/><p:sldId id="257" r:id="rIdSlide1"/></p:sldIdLst>
      <p:sldSz cx="12192000" cy="6858000"/>
      <p:custShowLst><p:custShow name="Báo cáo nhanh" id="7"><p:sldLst><p:sld r:id="rIdSlide1"/><p:sld r:id="rIdSlide2"/></p:sldLst></p:custShow></p:custShowLst>
    </p:presentation>`);
  addText(zip, 'ppt/_rels/presentation.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rIdSlide1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
      <Relationship Id="rIdSlide2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/>
      <Relationship Id="rIdMaster" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
    </Relationships>`);
  addText(zip, 'ppt/slides/slide2.xml', slideXml('Xem chi tiết', '<a:hlinkClick r:id="rIdTarget" action="ppaction://hlinksldjump" tooltip="Đến slide đích"/>'));
  addText(zip, 'ppt/slides/slide1.xml', slideXml('Slide đích'));
  addText(zip, 'ppt/slides/_rels/slide2.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rIdLayout" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
      <Relationship Id="rIdTarget" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slide1.xml"/>
    </Relationships>`);
  addText(zip, 'ppt/slides/_rels/slide1.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rIdLayout" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
    </Relationships>`);
  addText(zip, 'ppt/slideLayouts/slideLayout1.xml', `<?xml version="1.0" encoding="UTF-8"?>
    <p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" type="title" matchingName="Tiêu đề"><p:cSld name="Bố cục tiêu đề"><p:spTree/></p:cSld></p:sldLayout>`);
  addText(zip, 'ppt/slideLayouts/_rels/slideLayout1.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdMaster" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/></Relationships>`);
  addText(zip, 'ppt/slideMasters/slideMaster1.xml', `<?xml version="1.0" encoding="UTF-8"?>
    <p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" preserve="1">
      <p:cSld name="Master Trung tâm"><p:bg/><p:spTree>
        <p:pic><p:nvPicPr><p:cNvPr id="5" name="Logo trung tâm"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr><p:blipFill/><p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="914400" cy="914400"/></a:xfrm></p:spPr></p:pic>
        <p:sp><p:nvSpPr><p:cNvPr id="6" name="Tiêu đề Master"/><p:cNvSpPr/><p:nvPr><p:ph type="title" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>Tiêu đề mẫu</a:t></a:r></a:p></p:txBody></p:sp>
      </p:spTree></p:cSld>
      <p:hf ftr="1" sldNum="1" dt="0" hdr="0"/>
      <p:txStyles><p:titleStyle><a:lvl1pPr algn="ctr"><a:defRPr sz="3200" b="1"><a:latin typeface="Arial"/></a:defRPr></a:lvl1pPr></p:titleStyle><p:bodyStyle/><p:otherStyle/></p:txStyles>
    </p:sldMaster>`);
  addText(zip, 'ppt/slideMasters/_rels/slideMaster1.xml.rels', `<?xml version="1.0" encoding="UTF-8"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rIdLayout" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
      <Relationship Id="rIdTheme" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
    </Relationships>`);
  addText(zip, 'ppt/theme/theme1.xml', `<?xml version="1.0" encoding="UTF-8"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Theme Trung tâm"><a:themeElements><a:clrScheme name="Màu Trung tâm"/></a:themeElements></a:theme>`);
  zip.writeZip(filePath);
}

test('PowerPoint parser exposes detailed masters, custom shows and internal slide links', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'lms-ppt-advanced-'));
  const filePath = path.join(directory, 'advanced.pptx');
  try {
    createAdvancedPowerPointFixture(filePath);
    const document = await extractStructuredDocument(filePath);
    assert.deepEqual(document.slides.map(slide => slide.file), ['ppt/slides/slide2.xml', 'ppt/slides/slide1.xml']);
    assert.equal(document.slide_masters.length, 1);
    assert.equal(document.slide_masters[0].name, 'Master Trung tâm');
    assert.equal(document.slide_masters[0].background, true);
    assert.equal(document.slide_masters[0].objects.some(object => object.type === 'image' && object.name === 'Logo trung tâm'), true);
    assert.equal(document.slide_masters[0].placeholders[0].type, 'title');
    assert.equal(document.slide_masters[0].text_styles.title[0].font_family, 'Arial');
    assert.deepEqual(document.custom_slide_shows[0].slides, [2, 1]);
    assert.deepEqual(document.internal_hyperlinks[0], {
      trigger: 'click', relationship_id: 'rIdTarget', relationship_type: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide',
      target_mode: 'Internal', target: 'ppt/slides/slide1.xml', target_file: 'ppt/slides/slide1.xml', target_slide: 2,
      action: 'ppaction://hlinksldjump', navigation: null, custom_show_id: null, tooltip: 'Đến slide đích', link_type: 'internal_slide',
      object_id: '2', object_name: 'Nút chi tiết', object_text: 'Xem chi tiết', source_slide: 1,
    });
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
