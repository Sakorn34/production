<?php
/**
 * includes/unused_images.php — หารูปใน uploads/ ที่ไม่มีข้อมูลไหนอ้างถึงแล้ว (ใช้ในหน้า unused_images.php)
 *
 * ที่ที่อ้างถึงรูป (ตรวจจากฐานจริง 18 ก.ย. 2026 — ฐานสต็อกกับฐานเช่าไม่มีคอลัมน์รูป):
 *   products.icon_path · parts.icon_path (แอปอะไหล่ใช้ตารางนี้ด้วย) · update_logs.image1/image2
 *   support_reports.images_json · site_settings (โลโก้ · favicon · ฟอนต์ ฯลฯ — เทียบแบบหาชื่อไฟล์ในค่าทั้งหมด)
 * ค่าที่ขึ้นต้นด้วยโฟลเดอร์ AppSheet เดิม (Parts_Images/ ฯลฯ) อยู่ใต้ uploads/legacy/ — แบบเดียวกับ img_url()
 *
 * ไม่ลบทันที: ย้ายไปถังขยะ uploads/_trash/<เวลา>/ ก่อน กู้คืนได้ · ลบถาวรเป็นอีกปุ่มแยก
 * รูปที่เพิ่งอัปโหลดไม่เกิน 1 วันไม่นับ (อาจเป็นฟอร์มที่ยังกรอกไม่เสร็จ)
 */

/** @var string[] โฟลเดอร์รูปจาก AppSheet เดิม — ต้องตรงกับ img_url() */
const UNUSED_IMG_LEGACY_FOLDERS = ['Update_Images/', 'Parts_Images/', 'Menu Product_Images/', 'model appsheet_Images/',
    'model_Images/', 'NamePart_Images/', 'Sub Menu_Images/', 'Sub Product_Images/', 'Thumbnail_Images/'];
/** @var int ไม่นับรูปที่อายุน้อยกว่านี้ (วินาที) */
const UNUSED_IMG_MIN_AGE = 86400;

/**
 * โฟลเดอร์ uploads
 *
 * @return string
 */
function unused_img_root(): string
{
    return dirname(__DIR__) . '/uploads';
}

/**
 * ค่าในฐานข้อมูล → path ไฟล์ใต้ uploads/ (แบบเดียวกับ img_url)
 *
 * @param string $p
 * @return string '' ถ้าเป็นลิงก์ภายนอกหรือว่าง
 */
function unused_img_ref_to_rel(string $p): string
{
    $p = trim(str_replace('\\', '/', $p));
    if ($p === '' || preg_match('#^https?://#i', $p)) {
        return '';
    }
    $p = ltrim($p, '/');
    foreach (UNUSED_IMG_LEGACY_FOLDERS as $lf) {
        if (strpos($p, $lf) === 0) {
            return 'legacy/' . $p;
        }
    }
    return $p;
}

/**
 * path รูปทั้งหมดที่ยังมีข้อมูลอ้างถึง
 *
 * @return array{refs:array<string,string>, settings_blob:string}  refs = [path => มาจากไหน]
 */
function unused_img_references(): array
{
    $refs = [];
    $add = function ($val, $src) use (&$refs) {
        $rel = unused_img_ref_to_rel((string) $val);
        if ($rel !== '' && !isset($refs[$rel])) {
            $refs[$rel] = $src;
        }
    };
    foreach ([['products', 'icon_path', 'รูปรุ่นสินค้า'], ['parts', 'icon_path', 'รูปอะไหล่'],
              ['update_logs', 'image1', 'รูปอัปเดต FW/HW'], ['update_logs', 'image2', 'รูปอัปเดต FW/HW']] as $t) {
        $res = @db()->query("SELECT {$t[1]} FROM {$t[0]} WHERE {$t[1]} IS NOT NULL AND {$t[1]} <> ''");
        if ($res) {
            while ($r = $res->fetch_row()) {
                $add($r[0], $t[2]);
            }
        }
    }
    $res = @db()->query("SELECT images_json FROM support_reports WHERE images_json IS NOT NULL");
    if ($res) {
        while ($r = $res->fetch_row()) {
            foreach (json_decode((string) $r[0], true) ?: [] as $im) {
                $add($im['full'] ?? '', 'รูปแนบเรื่องแจ้งปัญหา');
                $add($im['preview'] ?? '', 'รูปแนบเรื่องแจ้งปัญหา');
            }
        }
    }
    // ค่าตั้งค่าระบบ — เก็บ path ในรูปแบบต่าง ๆ (บางค่าเป็น JSON) จึงเทียบด้วยการหาข้อความแทน
    $blob = '';
    $res = @db()->query('SELECT sval FROM site_settings');
    if ($res) {
        while ($r = $res->fetch_row()) {
            $blob .= "\n" . str_replace('\\/', '/', (string) $r[0]);
        }
    }
    return ['refs' => $refs, 'settings_blob' => $blob];
}

/**
 * สแกนไฟล์ใน uploads/ แยกเป็นที่ใช้อยู่ / ไม่ได้ใช้ + ข้อมูลที่อ้างรูปแต่ไม่มีไฟล์
 *
 * @return array{unused:array<int,array{rel:string,size:int,mtime:int}>, used_n:int, used_bytes:int,
 *               unused_bytes:int, young_n:int, missing:array<string,string>, total_n:int}
 */
function unused_img_scan(): array
{
    $root = unused_img_root();
    $ref = unused_img_references();
    $refs = $ref['refs'];
    $blob = $ref['settings_blob'];
    $out = ['unused' => [], 'used_n' => 0, 'used_bytes' => 0, 'unused_bytes' => 0, 'young_n' => 0, 'missing' => [], 'total_n' => 0];
    $seen = [];
    if (!is_dir($root)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $imgExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'woff', 'woff2', 'ttf', 'otf'];
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($rel === '' || strpos($rel, '_trash/') === 0 || $rel[0] === '.' || strpos($rel, '/.') !== false) {
            continue;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, $imgExt, true)) {
            continue;   // .htaccess, README ฯลฯ
        }
        $out['total_n']++;
        $size = (int) $f->getSize();
        $seen[$rel] = true;
        if (isset($refs[$rel]) || ($blob !== '' && strpos($blob, $rel) !== false)) {
            $out['used_n']++;
            $out['used_bytes'] += $size;
            continue;
        }
        if (time() - $f->getMTime() < UNUSED_IMG_MIN_AGE) {
            $out['young_n']++;
            continue;
        }
        $out['unused'][] = ['rel' => $rel, 'size' => $size, 'mtime' => (int) $f->getMTime()];
        $out['unused_bytes'] += $size;
    }
    foreach ($refs as $rel => $src) {
        if (!isset($seen[$rel]) && !is_file($root . '/' . $rel)) {
            $out['missing'][$rel] = $src;
        }
    }
    usort($out['unused'], function ($a, $b) { return strcmp($a['rel'], $b['rel']); });
    return $out;
}

/**
 * path ที่ส่งมาจากฟอร์มต้องอยู่ใต้ uploads/ จริง ไม่ใช่ ../ ออกไปข้างนอก
 *
 * @param string $rel
 * @return bool
 */
function unused_img_rel_ok(string $rel): bool
{
    return $rel !== '' && strpos($rel, '..') === false && $rel[0] !== '/' && strpos($rel, '_trash/') !== 0
        && strpos($rel, "\0") === false;
}

/**
 * ย้ายรูปที่เลือกไปถังขยะ — ตรวจซ้ำว่ายังไม่มีข้อมูลอ้างถึงก่อนย้ายทุกไฟล์
 *
 * @param string[] $rels
 * @return array{moved:int, skipped:int, batch:string}
 */
function unused_img_trash(array $rels): array
{
    $root = unused_img_root();
    $ref = unused_img_references();
    $batch = date('Ymd_His');
    $moved = 0;
    $skipped = 0;
    foreach ($rels as $rel) {
        $rel = str_replace('\\', '/', (string) $rel);
        $src = $root . '/' . $rel;
        if (!unused_img_rel_ok($rel) || !is_file($src) || isset($ref['refs'][$rel]) || strpos($ref['settings_blob'], $rel) !== false) {
            $skipped++;
            continue;
        }
        $dst = $root . '/_trash/' . $batch . '/' . $rel;
        if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0755, true)) {
            $skipped++;
            continue;
        }
        if (@rename($src, $dst)) {
            $moved++;
        } else {
            $skipped++;
        }
    }
    return ['moved' => $moved, 'skipped' => $skipped, 'batch' => $batch];
}

/**
 * ชุดในถังขยะ
 *
 * @return array<int,array{batch:string, n:int, bytes:int}>
 */
function unused_img_trash_batches(): array
{
    $dir = unused_img_root() . '/_trash';
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $b) {
        if ($b === '.' || $b === '..' || !is_dir("$dir/$b")) {
            continue;
        }
        $n = 0;
        $bytes = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/$b", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $n++;
                $bytes += (int) $f->getSize();
            }
        }
        $out[] = ['batch' => $b, 'n' => $n, 'bytes' => $bytes];
    }
    return $out;
}

/**
 * กู้คืนทั้งชุดกลับที่เดิม (ถ้าที่เดิมมีไฟล์ชื่อเดียวกันแล้วจะข้าม)
 *
 * @param string $batch
 * @return int จำนวนที่กู้คืน
 */
function unused_img_restore(string $batch): int
{
    if (!preg_match('/^\d{8}_\d{6}$/', $batch)) {
        return 0;
    }
    $root = unused_img_root();
    $dir = "$root/_trash/$batch";
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isFile()) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            $dst = "$root/$rel";
            if (!is_file($dst) && (is_dir(dirname($dst)) || @mkdir(dirname($dst), 0755, true)) && @rename($f->getPathname(), $dst)) {
                $n++;
            }
        } else {
            @rmdir($f->getPathname());
        }
    }
    @rmdir($dir);
    return $n;
}

/**
 * ลบทั้งชุดในถังขยะถาวร
 *
 * @param string $batch
 * @return int จำนวนไฟล์ที่ลบ
 */
function unused_img_purge(string $batch): int
{
    if (!preg_match('/^\d{8}_\d{6}$/', $batch)) {
        return 0;
    }
    $dir = unused_img_root() . "/_trash/$batch";
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isFile()) {
            if (@unlink($f->getPathname())) {
                $n++;
            }
        } else {
            @rmdir($f->getPathname());
        }
    }
    @rmdir($dir);
    return $n;
}
