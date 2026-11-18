<?php
/**
 * includes/xlsx_reader.php — อ่านไฟล์ .xlsx แบบเบา (ไม่ใช้ library ภายนอก)
 *
 * วัตถุประสงค์: อ่าน sheet แรกเป็น associative array จากแถวหัวตาราง
 * องค์ประกอบ: xlsx_read_rows(), xlsx_read_shared_strings(), xlsx_cell_value()
 *
 * Flow:
 *   $rows = xlsx_read_rows('/path/file.xlsx');
 *   // คืน [['Code'=>'P00001','Name'=>'...', ...], ...]
 *
 * จำกัด: รองรับ cell แบบ shared string และ inline string/number เท่านั้น
 */

/**
 * อ่านแถวข้อมูลจาก sheet แรกของไฟล์ xlsx
 *
 * @param string $filePath path ไปยังไฟล์ .xlsx
 * @param int $sheetIndex index sheet (0 = sheet แรก)
 * @return array<int,array<string,string>> แถวข้อมูล assoc จากหัวคอลัมน์แถวที่ 1
 */
function xlsx_read_rows(string $filePath, int $sheetIndex = 0): array
{
    if (!is_readable($filePath)) {
        throw new RuntimeException('ไม่พบหรืออ่านไฟล์ไม่ได้: ' . $filePath);
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('เปิดไฟล์ xlsx ไม่ได้: ' . $filePath);
    }

    $sharedStrings = xlsx_read_shared_strings($zip);
    $sheetXml = xlsx_read_sheet_xml($zip, $sheetIndex);
    $zip->close();

    if ($sheetXml === '') {
        throw new RuntimeException('ไม่พบ sheet ในไฟล์ xlsx');
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('parse sheet xml ไม่ได้');
    }

    $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rowNodes = $sheet->xpath('//m:sheetData/m:row') ?: [];

    $matrix = [];
    $maxCol = 0;
    foreach ($rowNodes as $rowNode) {
        $rowIndex = (int) ($rowNode['r'] ?? 0);
        if ($rowIndex <= 0) {
            continue;
        }
        $cells = [];
        foreach ($rowNode->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            if ($ref === '') {
                continue;
            }
            $colLetters = preg_replace('/\d+/', '', $ref);
            $colIndex = xlsx_column_index($colLetters);
            $cells[$colIndex] = xlsx_cell_value($cell, $sharedStrings);
            if ($colIndex > $maxCol) {
                $maxCol = $colIndex;
            }
        }
        $matrix[$rowIndex] = $cells;
    }

    if ($matrix === []) {
        return [];
    }

    ksort($matrix);
    $headerRowIndex = array_key_first($matrix);
    $headers = [];
    for ($c = 0; $c <= $maxCol; $c++) {
        $label = trim((string) ($matrix[$headerRowIndex][$c] ?? ''));
        $headers[$c] = $label !== '' ? $label : 'col_' . ($c + 1);
    }

    $rows = [];
    foreach ($matrix as $rowIndex => $cells) {
        if ($rowIndex === $headerRowIndex) {
            continue;
        }
        $assoc = [];
        $hasValue = false;
        for ($c = 0; $c <= $maxCol; $c++) {
            $value = trim((string) ($cells[$c] ?? ''));
            $assoc[$headers[$c]] = $value;
            if ($value !== '') {
                $hasValue = true;
            }
        }
        if ($hasValue) {
            $rows[] = $assoc;
        }
    }

    return $rows;
}

/**
 * อ่าน shared strings จาก zip xlsx
 *
 * @param ZipArchive $zip
 * @return array<int,string>
 */
function xlsx_read_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false || $xml === '') {
        return [];
    }

    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }

    $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $items = $doc->xpath('//m:si') ?: [];
    $strings = [];
    foreach ($items as $i => $si) {
        $text = '';
        if (isset($si->t)) {
            $text .= (string) $si->t;
        }
        foreach ($si->r as $run) {
            if (isset($run->t)) {
                $text .= (string) $run->t;
            }
        }
        $strings[(int) $i] = $text;
    }

    return $strings;
}

/**
 * โหลด xml ของ sheet ตาม index
 *
 * @param ZipArchive $zip
 * @param int $sheetIndex
 * @return string
 */
function xlsx_read_sheet_xml(ZipArchive $zip, int $sheetIndex): string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        return '';
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if ($workbook === false || $rels === false) {
        return '';
    }

    $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sheetNodes = $workbook->xpath('//m:sheets/m:sheet') ?: [];
    if (!isset($sheetNodes[$sheetIndex])) {
        return '';
    }

    $relId = (string) ($sheetNodes[$sheetIndex]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
    if ($relId === '') {
        return '';
    }

    $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relNodes = $rels->xpath('//r:Relationship[@Id="' . $relId . '"]') ?: [];
    if ($relNodes === []) {
        return '';
    }

    $target = (string) ($relNodes[0]['Target'] ?? '');
    if ($target === '') {
        return '';
    }

    $path = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
    $sheetXml = $zip->getFromName($path);
    return $sheetXml === false ? '' : $sheetXml;
}

/**
 * แปลงค่า cell xml เป็นสตริง
 *
 * @param SimpleXMLElement $cell
 * @param array<int,string> $sharedStrings
 * @return string
 */
function xlsx_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $type = (string) ($cell['t'] ?? '');
    if ($type === 's') {
        $idx = (int) ($cell->v ?? 0);
        return $sharedStrings[$idx] ?? '';
    }
    if ($type === 'inlineStr' && isset($cell->is->t)) {
        return (string) $cell->is->t;
    }
    if (isset($cell->v)) {
        return (string) $cell->v;
    }
    return '';
}

/**
 * แปลงชื่อคอลัมน์ Excel (A, B, AA) เป็น index 0-based
 *
 * @param string $letters
 * @return int
 */
function xlsx_column_index(string $letters): int
{
    $letters = strtoupper($letters);
    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}
