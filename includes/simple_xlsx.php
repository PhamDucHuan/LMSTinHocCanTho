<?php
declare(strict_types=1);

/**
 * Trình ghi XLSX tối giản, không cần thư viện ngoài.
 * Hỗ trợ nhiều sheet, định dạng ô, gộp ô, cố định hàng/cột và thiết lập in.
 */
final class SimpleXlsxWorkbook
{
    private array $sheets = [];

    public function addSheet(
        string $name,
        array $rows,
        array $columnWidths,
        array $merges = [],
        array $options = []
    ): void {
        $name = preg_replace('~[\\\\/?:*\[\]]~u', ' ', trim($name)) ?: 'Sheet';
        $name = function_exists('mb_substr')
            ? mb_substr($name, 0, 31, 'UTF-8')
            : substr($name, 0, 31);
        $this->sheets[] = compact('name', 'rows', 'columnWidths', 'merges', 'options');
    }

    public function save(string $path): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP chưa bật extension zip (ZipArchive), không thể tạo file XLSX.');
        }
        if ($this->sheets === []) {
            throw new RuntimeException('Workbook chưa có worksheet.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không thể tạo file XLSX tạm.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
            $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
            $zip->addFromString('docProps/core.xml', $this->corePropertiesXml());
            $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
            $zip->addFromString('xl/styles.xml', $this->stylesXml());

            foreach ($this->sheets as $index => $sheet) {
                $zip->addFromString(
                    'xl/worksheets/sheet' . ($index + 1) . '.xml',
                    $this->worksheetXml($sheet)
                );
            }
        } finally {
            $zip->close();
        }
    }

    private function contentTypesXml(): string
    {
        $worksheets = '';
        foreach ($this->sheets as $index => $_sheet) {
            $worksheets .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1)
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $worksheets . '</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="' . self::xml((string) $sheet['name']) . '" sheetId="'
                . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheets . '</sheets>'
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        $relationships = '';
        foreach ($this->sheets as $index => $_sheet) {
            $relationships .= '<Relationship Id="rId' . ($index + 1)
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }
        $relationships .= '<Relationship Id="rId' . (count($this->sheets) + 1)
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relationships . '</Relationships>';
    }

    private function corePropertiesXml(): string
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>LMS Tin Học Cần Thơ</dc:creator><cp:lastModifiedBy>LMS Tin Học Cần Thơ</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified></cp:coreProperties>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>LMS Tin Học Cần Thơ</Application><AppVersion>1.0</AppVersion></Properties>';
    }

    private function worksheetXml(array $sheet): string
    {
        $rowsXml = '';
        $maxRow = max(1, count($sheet['rows']));
        $maxColumn = max(1, count($sheet['columnWidths']));

        foreach ($sheet['rows'] as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $height = isset($row['height']) ? ' ht="' . (float) $row['height'] . '" customHeight="1"' : '';
            $cellsXml = '';
            foreach (($row['cells'] ?? []) as $column => $cell) {
                $column = (int) $column;
                $maxColumn = max($maxColumn, $column);
                $reference = self::columnName($column) . $rowNumber;
                $style = isset($cell['style']) ? ' s="' . (int) $cell['style'] . '"' : '';
                $value = $cell['value'] ?? '';
                if (($cell['type'] ?? 'string') === 'number' && is_numeric($value)) {
                    $cellsXml .= '<c r="' . $reference . '"' . $style . '><v>' . (0 + $value) . '</v></c>';
                } else {
                    $text = self::cleanText((string) $value);
                    $preserve = preg_match('/^\s|\s$/u', $text) ? ' xml:space="preserve"' : '';
                    $cellsXml .= '<c r="' . $reference . '" t="inlineStr"' . $style
                        . '><is><t' . $preserve . '>' . self::xml($text) . '</t></is></c>';
                }
            }
            $rowsXml .= '<row r="' . $rowNumber . '"' . $height . '>' . $cellsXml . '</row>';
        }

        $columnsXml = '';
        foreach ($sheet['columnWidths'] as $index => $width) {
            $column = $index + 1;
            $columnsXml .= '<col min="' . $column . '" max="' . $column . '" width="'
                . (float) $width . '" customWidth="1"/>';
        }

        $mergeXml = '';
        if ($sheet['merges'] !== []) {
            foreach ($sheet['merges'] as $range) {
                $mergeXml .= '<mergeCell ref="' . self::xml((string) $range) . '"/>';
            }
            $mergeXml = '<mergeCells count="' . count($sheet['merges']) . '">' . $mergeXml . '</mergeCells>';
        }

        $options = $sheet['options'];
        $freezeRows = max(0, (int) ($options['freeze_rows'] ?? 0));
        $freezeColumns = max(0, (int) ($options['freeze_columns'] ?? 0));
        $paneXml = '';
        if ($freezeRows > 0 || $freezeColumns > 0) {
            $topLeft = self::columnName($freezeColumns + 1) . ($freezeRows + 1);
            $paneXml = '<pane'
                . ($freezeColumns > 0 ? ' xSplit="' . $freezeColumns . '"' : '')
                . ($freezeRows > 0 ? ' ySplit="' . $freezeRows . '"' : '')
                . ' topLeftCell="' . $topLeft . '" activePane="bottomRight" state="frozen"/>';
        }
        $sheetViews = '<sheetViews><sheetView workbookViewId="0">' . $paneXml . '</sheetView></sheetViews>';
        $dimension = 'A1:' . self::columnName($maxColumn) . $maxRow;
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dimension . '"/>' . $sheetViews
            . '<sheetFormatPr defaultRowHeight="18"/><cols>' . $columnsXml . '</cols>'
            . '<sheetData>' . $rowsXml . '</sheetData>' . $mergeXml
            . '<printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
            . '<headerFooter/>'
            . '</worksheet>';
    }

    /** Style IDs: 0 normal, 1 title, 2 subtitle, 3 section, 4 header, 5 body,
     * 6 number, 7 date group, 8 matrix header, 9 weekend, 10 class,
     * 11 occupied cell, 12 empty cell, 13/14/15 shift headers. */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="7">'
            . '<font><sz val="10"/><name val="Arial"/></font>'
            . '<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF475569"/><name val="Arial"/></font>'
            . '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Arial"/></font>'
            . '<font><b/><sz val="9"/><color rgb="FF12352A"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="13"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . self::fill('1F517E') . self::fill('334155') . self::fill('2563EB') . self::fill('D1FAE5')
            . self::fill('245986') . self::fill('3D3D3D') . self::fill('E8F1F8') . self::fill('EAF7F1')
            . self::fill('F8FAFC') . self::fill('EA580C') . self::fill('7C3AED') . '</fills>'
            . '<borders count="4"><border/><border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom></border>'
            . '<border><left style="thin"><color rgb="FF8BA9C1"/></left><right style="thin"><color rgb="FF8BA9C1"/></right><top style="thin"><color rgb="FF8BA9C1"/></top><bottom style="thin"><color rgb="FF8BA9C1"/></bottom></border>'
            . '<border><top style="thin"><color rgb="FF6EE7B7"/></top><bottom style="thin"><color rgb="FF6EE7B7"/></bottom></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="16">'
            . self::xf(0, 0, 0, 'vertical="center"')
            . self::xf(1, 2, 0, 'horizontal="center" vertical="center"')
            . self::xf(2, 0, 0, 'vertical="center"')
            . self::xf(3, 3, 0, 'vertical="center"')
            . self::xf(4, 4, 1, 'horizontal="center" vertical="center" wrapText="1"')
            . self::xf(0, 0, 1, 'vertical="top" wrapText="1"')
            . self::xf(0, 0, 1, 'horizontal="center" vertical="center"')
            . self::xf(5, 5, 3, 'vertical="center"')
            . self::xf(4, 6, 2, 'horizontal="center" vertical="center" wrapText="1"')
            . self::xf(4, 7, 2, 'horizontal="center" vertical="center" wrapText="1"')
            . self::xf(5, 8, 2, 'vertical="top" wrapText="1"')
            . self::xf(6, 9, 2, 'horizontal="center" vertical="center" wrapText="1"')
            . self::xf(0, 10, 1, 'horizontal="center" vertical="center"')
            . self::xf(3, 4, 0, 'vertical="center"')
            . self::xf(3, 11, 0, 'vertical="center"')
            . self::xf(3, 12, 0, 'vertical="center"')
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function fill(string $rgb): string
    {
        return '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
    }

    private static function xf(int $font, int $fill, int $border, string $alignment): string
    {
        return '<xf numFmtId="0" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border
            . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment '
            . $alignment . '/></xf>';
    }

    private static function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)) . $name;
            $column = intdiv($column, 26);
        }
        return $name;
    }

    private static function cleanText(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars(self::cleanText($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
