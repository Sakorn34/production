<?php
/**
 * pdf_text_extract.php — ดึงข้อความจาก PDF แบบง่าย (ไม่ต้องพึ่ง library ภายนอก)
 *
 * ใช้กับ PDF ที่ encode ข้อความแบบ FlateDecode + Tj/TJ operators
 * รัน CLI: php database/tools/pdf_text_extract.php "path/to/file.pdf"
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php pdf_text_extract.php <pdf-file>\n");
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "Cannot read file\n");
    exit(1);
}

// ─ helpers ───────────────────────────────────────────────────────────────────

/**
 * ถอด stream ที่บีบอัดด้วย FlateDecode
 *
 * @param string $data ข้อมูลดิบของ stream
 * @return string|null
 */
function pdf_inflate($data)
{
    $out = @gzuncompress($data);
    if ($out !== false) {
        return $out;
    }
    $out = @gzinflate($data);
    if ($out !== false) {
        return $out;
    }
    // บาง PDF ใส่ header 2 byte ก่อน deflate
    if (strlen($data) > 2) {
        $out = @gzinflate(substr($data, 2));
        if ($out !== false) {
            return $out;
        }
    }
    return null;
}

/**
 * ดึงข้อความจาก content stream ของ PDF
 *
 * @param string $content
 * @return string
 */
function pdf_extract_strings($content)
{
    $text = '';
    // (Hello) Tj หรือ [(Hello)] TJ
    if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*T[jJ]/s', $content, $m)) {
        foreach ($m[0] as $chunk) {
            if (!preg_match('/\(((?:\\\\.|[^\\\\)])*)\)/s', $chunk, $mm)) {
                continue;
            }
            $text .= pdf_unescape($mm[1]) . "\t";
        }
    }
    // <48656C6C6F> Tj (hex string)
    if (preg_match_all('/<([0-9A-Fa-f]+)>\s*T[jJ]/', $content, $m)) {
        foreach ($m[1] as $hex) {
            $text .= pdf_hex_to_text($hex) . "\t";
        }
    }
    return $text;
}

/**
 * แปลง escape sequence ใน PDF string literal
 *
 * @param string $s
 * @return string
 */
function pdf_unescape($s)
{
    $s = preg_replace_callback('/\\\\([nrtbf()\\\\])/', function ($m) {
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\b", 'f' => "\f"];
        return isset($map[$m[1]]) ? $map[$m[1]] : $m[1];
    }, $s);
    $s = preg_replace('/\\\\([0-7]{1,3})/', '', $s);
    return $s;
}

/**
 * แปลง hex string เป็น UTF-8/ASCII
 *
 * @param string $hex
 * @return string
 */
function pdf_hex_to_text($hex)
{
    if ($hex === '') {
        return '';
    }
    if (strlen($hex) % 2 === 1) {
        $hex .= '0';
    }
    $bin = hex2bin($hex);
    return $bin === false ? '' : $bin;
}

// ─ extract streams ───────────────────────────────────────────────────────────

$all = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams, PREG_SET_ORDER)) {
    foreach ($streams as $st) {
        $body = $st[1];
        $inflated = pdf_inflate($body);
        $chunk = $inflated !== null ? $inflated : $body;
        $all .= pdf_extract_strings($chunk);
        $all .= "\n";
    }
}

// fallback: หา literal string ทั้งไฟล์
if (trim($all) === '') {
    if (preg_match_all('/\((?:\\\\.|[^\\\\)]){2,}\)/s', $raw, $m)) {
        foreach ($m[0] as $lit) {
            $inner = substr($lit, 1, -1);
            $all .= pdf_unescape($inner) . "\t";
        }
        $all .= "\n";
    }
}

echo $all;
