<?php
declare(strict_types=1);

/**
 * Read the first worksheet from a CSV/XLSX file.
 * The first non-empty row is treated as the header row.
 *
 * @return array{headers: list<string>, rows: list<list<string>>}
 */
function readTabularImport(string $path, string $extension, int $maxRows = 2000, int $maxColumns = 100): array
{
    $extension = strtolower($extension);
    $rawRows = match ($extension) {
        'csv' => readTabularCsv($path, $maxRows + 1, $maxColumns),
        'xlsx' => readTabularXlsx($path, $maxRows + 1, $maxColumns),
        default => throw new RuntimeException('Chỉ hỗ trợ file .xlsx hoặc .csv.'),
    };

    while ($rawRows && !array_filter($rawRows[0], static fn(string $value): bool => trim($value) !== '')) {
        array_shift($rawRows);
    }
    if (!$rawRows) throw new RuntimeException('File không có dữ liệu.');

    $headerRow = array_shift($rawRows);
    $lastColumn = -1;
    foreach ($headerRow as $index => $value) {
        if (trim($value) !== '') $lastColumn = $index;
    }
    if ($lastColumn < 0) throw new RuntimeException('Hàng tiêu đề trong file đang trống.');

    $headers = [];
    $usedHeaders = [];
    for ($index = 0; $index <= $lastColumn; $index++) {
        $base = trim((string) ($headerRow[$index] ?? ''));
        if ($base === '') $base = 'Cột ' . tabularColumnName($index);
        $label = $base;
        $suffix = 2;
        while (isset($usedHeaders[mb_strtolower($label, 'UTF-8')])) {
            $label = $base . ' (' . $suffix++ . ')';
        }
        $usedHeaders[mb_strtolower($label, 'UTF-8')] = true;
        $headers[] = $label;
    }

    $rows = [];
    foreach ($rawRows as $row) {
        $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
        if (!array_filter($row, static fn(string $value): bool => trim($value) !== '')) continue;
        foreach ($row as $column => $value) {
            $row[$column] = tabularNormaliseValueForHeader(trim((string) $value), $headers[$column] ?? '');
        }
        $rows[] = $row;
        if (count($rows) >= $maxRows) break;
    }
    if (!$rows) throw new RuntimeException('File chỉ có tiêu đề, chưa có hàng dữ liệu để điền.');
    return ['headers' => $headers, 'rows' => $rows];
}

function readTabularCsv(string $path, int $maxRows, int $maxColumns): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('Không thể đọc file CSV.');
    try {
        $sample = (string) fgets($handle);
        rewind($handle);
        $delimiters = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($delimiters);
        $delimiter = (string) array_key_first($delimiters);
        $rows = [];
        while (count($rows) < $maxRows && ($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) ?? (string) $row[0];
            $rows[] = array_map(static fn($value): string => (string) $value, array_slice($row, 0, $maxColumns));
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

function readTabularXlsx(string $path, int $maxRows, int $maxColumns): array
{
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('Máy chủ chưa bật ZipArchive để đọc Excel.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Không thể mở file Excel. File có thể bị hỏng.');
    try {
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $document = new DOMDocument();
            if (@$document->loadXML($sharedXml, LIBXML_NONET | LIBXML_COMPACT)) {
                $xpath = new DOMXPath($document);
                foreach ($xpath->query('//*[local-name()="si"]') as $item) {
                    $text = '';
                    foreach ($xpath->query('.//*[local-name()="t"]', $item) as $node) $text .= $node->textContent;
                    $sharedStrings[] = $text;
                }
            }
        }

        $dateStyles = tabularDateStyles($zip);
        $uses1904Dates = tabularWorkbookUses1904Dates($zip);

        $sheetPath = tabularFirstSheetPath($zip);
        $stat = $zip->statName($sheetPath);
        if (($stat['size'] ?? 0) > 30 * 1024 * 1024) throw new RuntimeException('Trang tính quá lớn để xử lý an toàn (tối đa 30 MB dữ liệu giải nén).');
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) throw new RuntimeException('Không tìm thấy trang tính đầu tiên trong file Excel.');
        $document = new DOMDocument();
        if (!@$document->loadXML($sheetXml, LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('Dữ liệu Excel không hợp lệ.');
        $xpath = new DOMXPath($document);
        $rows = [];
        foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
            if (!$rowNode instanceof DOMElement) continue;
            $row = [];
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cell) {
                if (!$cell instanceof DOMElement || !preg_match('/^([A-Z]+)/', strtoupper($cell->getAttribute('r')), $match)) continue;
                $column = tabularColumnIndex($match[1]);
                if ($column < 0 || $column >= $maxColumns) continue;
                $type = $cell->getAttribute('t');
                $value = '';
                if ($type === 'inlineStr') {
                    foreach ($xpath->query('.//*[local-name()="t"]', $cell) as $node) $value .= $node->textContent;
                } else {
                    $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
                    $rawValue = $valueNode?->textContent ?? '';
                    $styleIndex = (int) ($cell->getAttribute('s') ?: 0);
                    if ($type === 's') {
                        $value = $sharedStrings[(int) $rawValue] ?? '';
                    } elseif ($type === 'b') {
                        $value = $rawValue === '1' ? 'Có' : 'Không';
                    } elseif ($rawValue !== '' && is_numeric($rawValue) && isset($dateStyles[$styleIndex])) {
                        $value = tabularExcelSerialDate((float) $rawValue, $dateStyles[$styleIndex], $uses1904Dates);
                    } else {
                        $value = $rawValue;
                    }
                }
                $row[$column] = (string) $value;
            }
            if ($row) {
                $width = min($maxColumns, max(array_keys($row)) + 1);
                $dense = array_fill(0, $width, '');
                foreach ($row as $column => $value) $dense[$column] = $value;
                $rows[] = $dense;
            }
            if (count($rows) >= $maxRows) break;
        }
        return $rows;
    } finally {
        $zip->close();
    }
}

/** @return array<int, 'date'|'time'|'datetime'> */
function tabularDateStyles(ZipArchive $zip): array
{
    $stylesXml = $zip->getFromName('xl/styles.xml');
    if ($stylesXml === false) return [];
    $document = new DOMDocument();
    if (!@$document->loadXML($stylesXml, LIBXML_NONET | LIBXML_COMPACT)) return [];
    $xpath = new DOMXPath($document);
    $customFormats = [];
    foreach ($xpath->query('//*[local-name()="numFmts"]/*[local-name()="numFmt"]') as $format) {
        if (!$format instanceof DOMElement) continue;
        $customFormats[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
    }
    $dateIds = array_fill_keys(array_merge(range(14, 17), range(27, 36), range(50, 58)), 'date');
    $timeIds = array_fill_keys(array_merge(range(18, 21), range(45, 47)), 'time');
    $dateIds[22] = 'datetime';
    $styles = [];
    $styleIndex = 0;
    foreach ($xpath->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $format) {
        if (!$format instanceof DOMElement) continue;
        $formatId = (int) $format->getAttribute('numFmtId');
        if (isset($dateIds[$formatId])) {
            $styles[$styleIndex] = $dateIds[$formatId];
        } elseif (isset($timeIds[$formatId])) {
            $styles[$styleIndex] = $timeIds[$formatId];
        } elseif (isset($customFormats[$formatId])) {
            $code = strtolower($customFormats[$formatId]);
            $code = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/u', '', $code) ?? $code;
            $hasDate = str_contains($code, 'y') || str_contains($code, 'd');
            $hasTime = str_contains($code, 'h') || str_contains($code, 's');
            if ($hasDate) $styles[$styleIndex] = $hasTime ? 'datetime' : 'date';
            elseif ($hasTime) $styles[$styleIndex] = 'time';
        }
        $styleIndex++;
    }
    return $styles;
}

function tabularWorkbookUses1904Dates(ZipArchive $zip): bool
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if ($workbookXml === false) return false;
    $document = new DOMDocument();
    if (!@$document->loadXML($workbookXml, LIBXML_NONET | LIBXML_COMPACT)) return false;
    $properties = (new DOMXPath($document))->query('//*[local-name()="workbookPr"]')->item(0);
    return $properties instanceof DOMElement && in_array(strtolower($properties->getAttribute('date1904')), ['1', 'true'], true);
}

function tabularExcelSerialDate(float $serial, string $kind = 'date', bool $uses1904Dates = false): string
{
    $days = (int) floor($serial);
    $seconds = (int) round(($serial - $days) * 86400);
    $base = new DateTimeImmutable($uses1904Dates ? '1904-01-01 00:00:00' : '1899-12-30 00:00:00', new DateTimeZone('UTC'));
    $date = $base->modify(($days >= 0 ? '+' : '') . $days . ' days')->modify('+' . $seconds . ' seconds');
    return match ($kind) {
        'time' => $date->format($date->format('s') === '00' ? 'H:i' : 'H:i:s'),
        'datetime' => $date->format($date->format('s') === '00' ? 'd/m/Y H:i' : 'd/m/Y H:i:s'),
        default => $date->format('d/m/Y'),
    };
}

function tabularNormaliseValueForHeader(string $value, string $header): string
{
    $header = mb_strtolower(trim($header), 'UTF-8');
    $looksLikeDate = (bool) preg_match('/(^|\s)(ngày|date|dob)(\s|$)/ui', $header)
        && !preg_match('/(^|\s)số\s+ngày(\s|$)/ui', $header);
    if (!$looksLikeDate || $value === '') return $value;
    if (is_numeric($value) && (float) $value >= 10000 && (float) $value <= 100000) {
        return tabularExcelSerialDate((float) $value);
    }
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(.*)$/', $value, $match)) {
        return sprintf('%02d/%02d/%04d%s', (int) $match[3], (int) $match[2], (int) $match[1], $match[4]);
    }
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(.*)$/', $value, $match)) {
        return sprintf('%02d/%02d/%04d%s', (int) $match[1], (int) $match[2], (int) $match[3], $match[4]);
    }
    return $value;
}

function tabularFirstSheetPath(ZipArchive $zip): string
{
    $default = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relationshipsXml === false) return $default;
    $workbook = new DOMDocument();
    $relationships = new DOMDocument();
    if (!@$workbook->loadXML($workbookXml, LIBXML_NONET) || !@$relationships->loadXML($relationshipsXml, LIBXML_NONET)) return $default;
    $sheet = (new DOMXPath($workbook))->query('//*[local-name()="sheet"]')->item(0);
    if (!$sheet instanceof DOMElement) return $default;
    $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
    foreach ((new DOMXPath($relationships))->query('//*[local-name()="Relationship"]') as $relationship) {
        if (!$relationship instanceof DOMElement || $relationship->getAttribute('Id') !== $relationshipId) continue;
        $parts = explode('/', 'xl/' . ltrim(str_replace('\\', '/', $relationship->getAttribute('Target')), '/'));
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($resolved); else $resolved[] = $part;
        }
        return implode('/', $resolved);
    }
    return $default;
}

function tabularColumnIndex(string $letters): int
{
    $number = 0;
    foreach (str_split(strtoupper($letters)) as $letter) $number = $number * 26 + ord($letter) - 64;
    return $number - 1;
}

function tabularColumnName(int $index): string
{
    $name = '';
    for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) $name = chr(65 + (($number - 1) % 26)) . $name;
    return $name;
}
