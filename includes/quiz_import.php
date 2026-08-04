<?php
declare(strict_types=1);

/**
 * Đọc file câu hỏi CSV hoặc Excel .xlsx và trả về các dòng dữ liệu.
 * Mỗi dòng có tối đa 8 cột để dùng chung cho bài trắc nghiệm và ngân hàng câu hỏi.
 */
function readQuizImportRows(string $path, string $extension): array
{
    return match (strtolower($extension)) {
        'csv' => readQuizCsvRows($path),
        'xlsx' => readQuizXlsxRows($path),
        default => throw new RuntimeException('Chỉ chấp nhận file .csv hoặc .xlsx.'),
    };
}

function readQuizCsvRows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Không thể đọc file CSV.');
    }
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
        }
        $rows[] = array_map(static fn($value) => trim((string) $value), $row);
    }
    fclose($handle);
    return $rows;
}

function readQuizXlsxRows(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Máy chủ chưa bật PHP ZipArchive để đọc file Excel.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Không thể mở file Excel. File có thể bị hỏng hoặc không đúng định dạng .xlsx.');
    }
    try {
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $document = new DOMDocument();
            if (@$document->loadXML($sharedXml)) {
                $xpath = new DOMXPath($document);
                foreach ($xpath->query('//*[local-name()="si"]') as $item) {
                    $text = '';
                    foreach ($xpath->query('.//*[local-name()="t"]', $item) as $textNode) {
                        $text .= $textNode->textContent;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml !== false && $relationshipsXml !== false) {
            $workbook = new DOMDocument();
            $relationships = new DOMDocument();
            if (@$workbook->loadXML($workbookXml) && @$relationships->loadXML($relationshipsXml)) {
                $workbookXpath = new DOMXPath($workbook);
                $firstSheet = $workbookXpath->query('//*[local-name()="sheet"]')->item(0);
                $relationshipId = $firstSheet?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if ($relationshipId) {
                    $relationshipXpath = new DOMXPath($relationships);
                    foreach ($relationshipXpath->query('//*[local-name()="Relationship"]') as $relationship) {
                        if ($relationship->getAttribute('Id') === $relationshipId) {
                            $target = str_replace('\\', '/', $relationship->getAttribute('Target'));
                            $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim($target, '/');
                            break;
                        }
                    }
                }
            }
        }

        $normaliseZipPath = static function (string $baseDirectory, string $target): string {
            if (str_starts_with($target, '/')) return ltrim($target, '/');
            $parts = explode('/', trim($baseDirectory . '/' . $target, '/'));
            $resolved = [];
            foreach ($parts as $part) {
                if ($part === '' || $part === '.') continue;
                if ($part === '..') { array_pop($resolved); continue; }
                $resolved[] = $part;
            }
            return implode('/', $resolved);
        };

        $cellImages = [];
        $sheetRelationshipPath = dirname($sheetPath) . '/_rels/' . basename($sheetPath) . '.rels';
        $sheetRelationshipXml = $zip->getFromName($sheetRelationshipPath);
        if ($sheetRelationshipXml !== false) {
            $relationships = new DOMDocument();
            if (@$relationships->loadXML($sheetRelationshipXml)) {
                $relationshipXpath = new DOMXPath($relationships);
                foreach ($relationshipXpath->query('//*[local-name()="Relationship"]') as $relationship) {
                    if (!str_ends_with($relationship->getAttribute('Type'), '/drawing')) continue;
                    $drawingPath = $normaliseZipPath(dirname($sheetPath), $relationship->getAttribute('Target'));
                    $drawingXml = $zip->getFromName($drawingPath);
                    $drawingRelationshipPath = dirname($drawingPath) . '/_rels/' . basename($drawingPath) . '.rels';
                    $drawingRelationshipXml = $zip->getFromName($drawingRelationshipPath);
                    if ($drawingXml === false || $drawingRelationshipXml === false) continue;

                    $drawingRelationships = [];
                    $drawingRelDocument = new DOMDocument();
                    if (@$drawingRelDocument->loadXML($drawingRelationshipXml)) {
                        $drawingRelXpath = new DOMXPath($drawingRelDocument);
                        foreach ($drawingRelXpath->query('//*[local-name()="Relationship"]') as $drawingRelationship) {
                            $drawingRelationships[$drawingRelationship->getAttribute('Id')] =
                                $normaliseZipPath(dirname($drawingPath), $drawingRelationship->getAttribute('Target'));
                        }
                    }
                    $drawingDocument = new DOMDocument();
                    if (!@$drawingDocument->loadXML($drawingXml)) continue;
                    $drawingXpath = new DOMXPath($drawingDocument);
                    foreach ($drawingXpath->query('//*[local-name()="twoCellAnchor" or local-name()="oneCellAnchor"]') as $anchor) {
                        $from = $drawingXpath->query('./*[local-name()="from"]', $anchor)->item(0);
                        $columnNode = $from ? $drawingXpath->query('./*[local-name()="col"]', $from)->item(0) : null;
                        $rowNode = $from ? $drawingXpath->query('./*[local-name()="row"]', $from)->item(0) : null;
                        $blip = $drawingXpath->query('.//*[local-name()="blip"]', $anchor)->item(0);
                        if (!$columnNode || !$rowNode || !$blip) continue;
                        $relationshipId = $blip->getAttributeNS(
                            'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                            'embed'
                        );
                        $mediaPath = $drawingRelationships[$relationshipId] ?? '';
                        $extension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
                        if (!$mediaPath || !in_array($extension, ['png','jpg','jpeg','gif','webp'], true)) continue;
                        $binary = $zip->getFromName($mediaPath);
                        if ($binary === false) continue;
                        $excelRow = (int) $rowNode->textContent + 1;
                        $excelColumn = (int) $columnNode->textContent;
                        if ($excelColumn > 5) continue;
                        $cellImages[$excelRow][$excelColumn][] = ['extension' => $extension, 'data' => $binary];
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            throw new RuntimeException('Không tìm thấy trang tính đầu tiên trong file Excel.');
        }
        $document = new DOMDocument();
        if (!@$document->loadXML($sheetXml)) {
            throw new RuntimeException('Dữ liệu trang tính Excel không hợp lệ.');
        }
        $xpath = new DOMXPath($document);
        $rows = [];
        foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
            $row = array_fill(0, 8, '');
            $excelRow = (int) ($rowNode->getAttribute('r') ?: count($rows) + 1);
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cell) {
                $reference = strtoupper($cell->getAttribute('r'));
                if (!preg_match('/^([A-Z]+)/', $reference, $match)) continue;
                $column = 0;
                foreach (str_split($match[1]) as $letter) {
                    $column = $column * 26 + (ord($letter) - 64);
                }
                $column--;
                if ($column < 0 || $column > 7) continue;

                $type = $cell->getAttribute('t');
                $value = '';
                if ($type === 'inlineStr') {
                    foreach ($xpath->query('.//*[local-name()="t"]', $cell) as $textNode) {
                        $value .= $textNode->textContent;
                    }
                } else {
                    $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
                    $rawValue = $valueNode?->textContent ?? '';
                    $value = $type === 's' ? ($sharedStrings[(int) $rawValue] ?? '') : $rawValue;
                }
                $row[$column] = trim($value);
            }
            if (isset($cellImages[$excelRow])) {
                $row['__images'] = $cellImages[$excelRow];
            }
            $rows[] = $row;
        }
        return $rows;
    } finally {
        $zip->close();
    }
}
