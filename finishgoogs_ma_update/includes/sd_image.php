<?php
/**
 * includes/sd_image.php — ตามชุด image ที่ write ลง SD card ของ bitVisitor
 * ────────────────────────────────────────────────────────────────────────────────
 * Plus / M ใช้ไฟล์คนละตัวตาม Raspberry Pi2 vs Pi3 · รุ่น S เป็น Pi3 อย่างเดียว
 * รอบ AL-26-10-14 ต้องเขียนใหม่เฉพาะ Pi3 (Plus/M) และรุ่น S
 * ค่าต่อเครื่องเก็บใน asset_components: Raspberry, SD Image
 * catalog เก็บไฟล์ปัจจุบันและไฟล์เก่ารายช่อง แต่ละไฟล์มี version FW เริ่มต้น
 * ถ้ายังไม่ยืนยัน จะเดาชุดจาก version FW ของเครื่อง (ไฟล์ใหม่สุดที่ FW ถึงจุดเริ่ม)
 * ไม่ใช้วันที่เริ่มชุด / วันผลิต / วันซ่อม มาเดาชุด
 * หน้า MA ไม่ต้องติ๊กเขียน — เทียบจาก version FW ที่เลือกในฟอร์ม ถ้าถึงจุดเริ่มชุดปัจจุบันจะบันทึกชื่อไฟล์ให้
 * เปลี่ยน SD Card ยังถือว่าเขียนชุดปัจจุบัน
 *
 * ตัวอย่าง: sd_image_info($asset) คืนไฟล์ที่ต้องใช้และว่าเขียนครบชุดปัจจุบันหรือยัง
 */

/** @var string ชื่อชิ้นส่วนที่เก็บรุ่นบอร์ด Pi */
const SD_IMAGE_RASPBERRY_KEY = 'Raspberry';
/** @var string ชื่อชิ้นส่วนที่เก็บชื่อไฟล์ image ที่เขียนล่าสุด */
const SD_IMAGE_FILE_KEY = 'SD Image';
/** @var string คีย์ใน site_settings สำหรับชื่อไฟล์ชุดปัจจุบัน */
const SD_IMAGE_SETTING_KEY = 'sd_image_catalog';

// ────────────────────────────────────────────────────────────────────────────────
// catalog
// ────────────────────────────────────────────────────────────────────────────────

/**
 * ชื่อไฟล์ image เริ่มต้น ถ้ายังไม่ได้ตั้งในระบบหลังบ้าน
 *
 * @return array<string,string> slot => ชื่อไฟล์
 */
function sd_image_files_default(): array
{
    return [
        'plus_m_pi2' => 'jessie_cross_compile_rev1.1(Plus-M Pi2)-23-08-29.img',
        'plus_m_pi3' => 'jessie_cross_compile_rev3.1(Plus-M) AL-26-10-14.img',
        's_pi3'      => 'jessie_cross_compile_rev3.1(S) AL-26-10-14.img',
    ];
}

/**
 * ป้ายช่องไฟล์สำหรับฟอร์มตั้งค่า
 *
 * @return array<string,string>
 */
function sd_image_slot_labels(): array
{
    return [
        'plus_m_pi2' => 'Plus / M · Pi2',
        'plus_m_pi3' => 'Plus / M · Pi3',
        's_pi3'      => 'S · Pi3',
    ];
}

/**
 * ตรวจชื่อไฟล์ .img ที่พิมพ์ในฟอร์ม — ห้าม path
 *
 * @param string $raw
 * @return string ชื่อไฟล์ที่ผ่าน หรือ '' ถ้าไม่ใช้ได้
 */
function sd_image_sanitize_filename(string $raw): string
{
    $t = trim($raw);
    if ($t === '' || strpbrk($t, "/\\:\0") !== false || strpos($t, '..') !== false) {
        return '';
    }
    $t = mb_substr($t, 0, 180);
    if (!preg_match('/^[A-Za-z0-9._() +\-]+\.img$/u', $t)) {
        return '';
    }
    return $t;
}

/**
 * ชื่อไฟล์ image ที่ใช้จริงในโฟลเดอร์ของทีม (อ่านจาก site_settings ถ้าระบุ)
 *
 * @return array<string,string> slot => ชื่อไฟล์
 */
function sd_image_files(): array
{
    $out = sd_image_files_default();
    $raw = json_decode((string) setting(SD_IMAGE_SETTING_KEY, ''), true);
    if (!is_array($raw) || empty($raw['files']) || !is_array($raw['files'])) {
        return $out;
    }
    foreach ($out as $slot => $_) {
        $fn = sd_image_sanitize_filename((string) ($raw['files'][$slot] ?? ''));
        if ($fn !== '') {
            $out[$slot] = $fn;
        }
    }
    return $out;
}

/**
 * ช่องที่ต้องไล่เขียนใหม่รอบนี้
 *
 * @return string[]
 */
function sd_image_campaign_slots(): array
{
    $allowed = array_keys(sd_image_files_default());
    $raw = json_decode((string) setting(SD_IMAGE_SETTING_KEY, ''), true);
    if (!is_array($raw) || !isset($raw['campaign']) || !is_array($raw['campaign'])) {
        return ['plus_m_pi3', 's_pi3'];
    }
    $out = [];
    foreach ($raw['campaign'] as $slot) {
        $slot = (string) $slot;
        if (in_array($slot, $allowed, true) && !in_array($slot, $out, true)) {
            $out[] = $slot;
        }
    }
    return $out;
}

/**
 * อ่าน version FW เริ่มต้นจากช่องกรอก — ถ้าระบุหลายค่าจะเก็บตัวที่เก่าสุด
 *
 * @param mixed $raw
 * @return string
 */
function sd_image_posted_fw_start($raw): string
{
    $posted = sd_image_parse_fw_list($raw);
    return $posted ? sd_image_fw_oldest($posted) : sd_image_sanitize_fw((string) $raw);
}

/**
 * บันทึกไฟล์ปัจจุบัน ไฟล์เก่าต่อช่อง และช่องที่ต้องเขียนรอบนี้
 *
 * @param array $post
 * @return array{ok:bool, message:string}
 */
function sd_image_save_catalog(array $post): array
{
    $files = [];
    $labels = sd_image_slot_labels();
    foreach (sd_image_files_default() as $slot => $_) {
        $fn = sd_image_sanitize_filename((string) ($post['sd_file'][$slot] ?? ''));
        if ($fn === '') {
            return ['ok' => false, 'message' => 'ชื่อไฟล์ของ ' . ($labels[$slot] ?? $slot) . ' ต้องเป็นไฟล์ .img (ห้ามมี / \\)'];
        }
        $files[$slot] = $fn;
    }
    $camp = [];
    foreach ((array) ($post['sd_campaign'] ?? []) as $slot) {
        $slot = (string) $slot;
        if (isset($files[$slot]) && !in_array($slot, $camp, true)) {
            $camp[] = $slot;
        }
    }
    $fwStart = [];
    $versions = [];
    foreach (array_keys($files) as $slot) {
        $fwStart[$slot] = sd_image_posted_fw_start($post['sd_fw_start'][$slot] ?? '');
        $list = [['file' => $files[$slot], 'fw_start' => $fwStart[$slot]]];
        $seen = [$files[$slot] => true];
        $hist = $post['sd_hist'][$slot] ?? [];
        if (!is_array($hist)) {
            $hist = [];
        }
        foreach ($hist as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fn = sd_image_sanitize_filename((string) ($row['file'] ?? ''));
            if ($fn === '') {
                $typed = trim((string) ($row['file'] ?? ''));
                if ($typed !== '') {
                    return ['ok' => false, 'message' => 'ชื่อไฟล์เก่าของ ' . ($labels[$slot] ?? $slot) . ' ต้องเป็นไฟล์ .img (ห้ามมี / \\)'];
                }
                continue;
            }
            if (isset($seen[$fn])) {
                continue;
            }
            $seen[$fn] = true;
            $oldFw = sd_image_posted_fw_start($row['fw_start'] ?? '');
            if ($oldFw === '') {
                return ['ok' => false, 'message' => 'ไฟล์เก่าของ ' . ($labels[$slot] ?? $slot) . ' ต้องมี FW เริ่มต้น'];
            }
            $list[] = ['file' => $fn, 'fw_start' => $oldFw];
        }
        if (count($list) > 1 && $fwStart[$slot] === '') {
            return ['ok' => false, 'message' => 'ช่อง ' . ($labels[$slot] ?? $slot) . ' มีไฟล์เก่า ต้องใส่ FW เริ่มต้นของไฟล์ปัจจุบันด้วย'];
        }
        $versions[$slot] = $list;
    }
    set_setting(SD_IMAGE_SETTING_KEY, json_encode([
        'files'     => $files,
        'campaign'  => $camp,
        'fw_start'  => $fwStart,
        'versions'  => $versions,
    ], JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'message' => 'บันทึกชุด image แล้ว'];
}

/**
 * ตรวจวันที่ Y-m-d จากฟอร์ม
 *
 * @param string $raw
 * @return string
 */
function sd_image_sanitize_date(string $raw): string
{
    $t = trim($raw);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
        return '';
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return '';
    }
    return $t;
}

/**
 * ตรวจ version FW ที่ใช้เทียบชุด image
 *
 * @param string $raw
 * @return string
 */
function sd_image_sanitize_fw(string $raw): string
{
    $t = trim(preg_replace('/\s+/', ' ', $raw));
    if ($t === '' || strlen($t) > 40) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._+\-]+(?: [A-Za-z0-9._+\-]+)*$/', $t)) {
        return '';
    }
    return $t;
}

/**
 * แยกรายการ version FW จากช่องกรอกหรือจาก catalog
 *
 * @param mixed $raw
 * @return string[]
 */
function sd_image_parse_fw_list($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[,;\n]+/', (string) $raw);
    }
    $out = [];
    $seen = [];
    foreach ($parts as $one) {
        $one = sd_image_sanitize_fw((string) $one);
        if ($one === '') {
            continue;
        }
        $norm = sd_image_fw_norm($one);
        if ($norm === '' || isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $out[] = $one;
    }
    return $out;
}

/**
 * ดึงวันที่จากชื่อไฟล์ เช่น AL-26-10-14 หรือ -23-08-29
 *
 * @param string $fn
 * @return string Y-m-d หรือ ''
 */
function sd_image_date_from_filename(string $fn): string
{
    if (!preg_match('/(?:AL-)?(\d{2})-(\d{2})-(\d{2})/i', $fn, $m)) {
        return '';
    }
    $yy = (int) $m[1];
    $year = $yy >= 70 ? 1900 + $yy : 2000 + $yy;
    return sd_image_sanitize_date(sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[3]));
}

/**
 * วันที่เริ่มชุดจากชื่อไฟล์ช่องที่ต้องเขียน — ใช้ไฟล์ที่ใหม่สุด
 *
 * @param array<string,string> $files
 * @param string[]             $camp
 * @return string
 */
function sd_image_since_from_files(array $files, array $camp): string
{
    $best = '';
    $slots = $camp ? $camp : array_keys($files);
    foreach ($slots as $slot) {
        $d = sd_image_date_from_filename((string) ($files[$slot] ?? ''));
        if ($d !== '' && ($best === '' || strcmp($d, $best) > 0)) {
            $best = $d;
        }
    }
    return $best;
}

/**
 * อ่าน JSON catalog ทั้งก้อน
 *
 * @return array<string,mixed>
 */
function sd_image_catalog_raw(): array
{
    $raw = json_decode((string) setting(SD_IMAGE_SETTING_KEY, ''), true);
    return is_array($raw) ? $raw : [];
}

/**
 * วันที่เริ่มใช้ชุด image รอบนี้ — ถ้าว่างจะอ่านจากชื่อไฟล์
 *
 * @return string Y-m-d หรือ ''
 */
function sd_image_campaign_since(): string
{
    $raw = sd_image_catalog_raw();
    $since = sd_image_sanitize_date((string) ($raw['since'] ?? ''));
    if ($since !== '') {
        return $since;
    }
    return sd_image_since_from_files(sd_image_files(), sd_image_campaign_slots());
}

/**
 * เลือก version FW ที่เก่าสุดจากรายการ
 *
 * @param string[] $list
 * @return string
 */
function sd_image_fw_oldest(array $list): string
{
    $best = '';
    foreach ($list as $one) {
        $one = sd_image_sanitize_fw((string) $one);
        if ($one === '') {
            continue;
        }
        if ($best === '') {
            $best = $one;
            continue;
        }
        if (sd_image_fw_parse($one) !== null && sd_image_fw_parse($best) !== null
            && sd_image_fw_cmp($one, $best) < 0) {
            $best = $one;
        }
    }
    return $best;
}

/**
 * version FW เริ่มต้นของชุด image แต่ละช่อง — ถ้าว่างจะไม่เทียบจาก FW ของช่องนั้น
 *
 * @return array<string,string> slot => FW เริ่มต้น
 */
function sd_image_fw_starts(): array
{
    $out = [];
    foreach (array_keys(sd_image_files_default()) as $slot) {
        $out[$slot] = '';
    }
    $raw = sd_image_catalog_raw();
    if (!empty($raw['fw_start']) && is_array($raw['fw_start'])) {
        foreach ($out as $slot => $_) {
            $v = $raw['fw_start'][$slot] ?? '';
            $out[$slot] = is_array($v)
                ? sd_image_fw_oldest(sd_image_parse_fw_list($v))
                : sd_image_sanitize_fw((string) $v);
        }
    }
    if (!empty($raw['fw_map']) && is_array($raw['fw_map'])) {
        foreach ($out as $slot => $cur) {
            if ($cur !== '') {
                continue;
            }
            $out[$slot] = sd_image_fw_oldest(sd_image_parse_fw_list($raw['fw_map'][$slot] ?? []));
        }
    }
    if (!empty($raw['fw']) && is_array($raw['fw'])) {
        $legacy = sd_image_fw_oldest(sd_image_parse_fw_list($raw['fw']));
        if ($legacy !== '') {
            foreach (sd_image_campaign_slots() as $slot) {
                if (isset($out[$slot]) && $out[$slot] === '') {
                    $out[$slot] = $legacy;
                }
            }
        }
    }
    if (!empty($raw['versions']) && is_array($raw['versions'])) {
        foreach ($out as $slot => $cur) {
            if ($cur !== '') {
                continue;
            }
            $row0 = is_array($raw['versions'][$slot] ?? null) ? ($raw['versions'][$slot][0] ?? null) : null;
            if (is_array($row0)) {
                $out[$slot] = sd_image_posted_fw_start($row0['fw_start'] ?? '');
            }
        }
    }
    return $out;
}

/**
 * รายการไฟล์ต่อช่อง — แถวแรกคือชุดปัจจุบัน ที่เหลือเป็นไฟล์เก่า
 * ของเดิมที่ยังไม่มี versions จะได้แค่ไฟล์ปัจจุบันจาก files + fw_start
 *
 * @return array<string,array<int,array{file:string,fw_start:string}>>
 */
function sd_image_versions(): array
{
    $files = sd_image_files();
    $starts = sd_image_fw_starts();
    $out = [];
    foreach ($files as $slot => $fn) {
        $out[$slot] = [['file' => $fn, 'fw_start' => (string) ($starts[$slot] ?? '')]];
    }
    $raw = sd_image_catalog_raw();
    if (empty($raw['versions']) || !is_array($raw['versions'])) {
        return $out;
    }
    foreach ($out as $slot => $list) {
        $curFile = $list[0]['file'];
        $seen = [$curFile => true];
        $extra = [];
        foreach ((array) ($raw['versions'][$slot] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fn = sd_image_sanitize_filename((string) ($row['file'] ?? ''));
            if ($fn === '' || isset($seen[$fn])) {
                continue;
            }
            $seen[$fn] = true;
            $extra[] = [
                'file'     => $fn,
                'fw_start' => sd_image_posted_fw_start($row['fw_start'] ?? ''),
            ];
        }
        $out[$slot] = array_merge($list, $extra);
    }
    return $out;
}

/**
 * เลือกไฟล์ใหม่สุดในช่องที่ version FW ของเครื่องถึงจุดเริ่ม
 *
 * @param string $slot
 * @param string $fw
 * @return array{file:string,fw_start:string}
 */
function sd_image_version_for_fw(string $slot, string $fw): array
{
    $empty = ['file' => '', 'fw_start' => ''];
    $fw = trim($fw);
    if ($slot === '' || $fw === '') {
        return $empty;
    }
    $best = $empty;
    $bestStart = '';
    foreach (sd_image_versions()[$slot] ?? [] as $row) {
        $file = (string) ($row['file'] ?? '');
        $start = trim((string) ($row['fw_start'] ?? ''));
        if ($file === '' || $start === '' || !sd_image_fw_at_least($fw, $start)) {
            continue;
        }
        if ($bestStart === '' || sd_image_fw_cmp($start, $bestStart) > 0) {
            $best = ['file' => $file, 'fw_start' => $start];
            $bestStart = $start;
        }
    }
    return $best;
}

/**
 * ชื่อไฟล์ที่เครื่องนี้ถูกเดา/ยืนยันว่าใช้อยู่ — ไม่ใช่ไฟล์ที่ต้องเขียนรอบนี้
 *
 * @param array<string,mixed> $info จาก sd_image_info()
 * @return string
 */
function sd_image_shown_file(array $info): string
{
    if (!empty($info['done'])) {
        return trim((string) ($info['have_file'] ?? ''));
    }
    $have = trim((string) ($info['have_file'] ?? ''));
    if ($have !== '') {
        return $have;
    }
    return trim((string) ($info['matched_file'] ?? ''));
}

/**
 * รวมช่องว่างใน version FW แล้วทำตัวพิมพ์เล็ก เพื่อเทียบ
 *
 * @param string $fw
 * @return string
 */
function sd_image_fw_norm(string $fw): string
{
    return strtolower(preg_replace('/\s+/', '', trim($fw)));
}

/**
 * แยก version FW เป็นตัวเลข + ตัวอักษรท้าย + v2
 *
 * @param string $fw
 * @return array{0:int,1:int,2:int,3:string,4:int}|null
 */
function sd_image_fw_parse(string $fw)
{
    $t = sd_image_fw_norm($fw);
    if ($t === '' || !preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?([a-z]*)(?:v(\d+))?$/', $t, $m)) {
        return null;
    }
    return [
        (int) $m[1],
        (int) ($m[2] ?? 0),
        (int) ($m[3] ?? 0),
        (string) ($m[4] ?? ''),
        (int) ($m[5] ?? 0),
    ];
}

/**
 * เทียบ version FW — ค่าที่ parse ไม่ได้ถือว่าเทียบลำดับไม่ได้
 *
 * @param string $a
 * @param string $b
 * @return int -1|0|1
 */
function sd_image_fw_cmp(string $a, string $b): int
{
    if (sd_image_fw_norm($a) === sd_image_fw_norm($b)) {
        return 0;
    }
    $pa = sd_image_fw_parse($a);
    $pb = sd_image_fw_parse($b);
    if ($pa === null || $pb === null) {
        return strcasecmp(sd_image_fw_norm($a), sd_image_fw_norm($b));
    }
    for ($i = 0; $i < 3; $i++) {
        if ($pa[$i] !== $pb[$i]) {
            return $pa[$i] < $pb[$i] ? -1 : 1;
        }
    }
    if ($pa[3] !== $pb[3]) {
        if ($pa[3] === '') {
            return -1;
        }
        if ($pb[3] === '') {
            return 1;
        }
        return strcmp($pa[3], $pb[3]);
    }
    if ($pa[4] !== $pb[4]) {
        return $pa[4] < $pb[4] ? -1 : 1;
    }
    return 0;
}

/**
 * เครื่องนี้เป็น FW ตั้งแต่จุดเริ่มชุดนั้นขึ้นไปหรือไม่
 *
 * @param string $fw
 * @param string $start
 * @return bool
 */
function sd_image_fw_at_least(string $fw, string $start): bool
{
    $fw = trim($fw);
    $start = trim($start);
    if ($fw === '' || $start === '') {
        return false;
    }
    if (sd_image_fw_norm($fw) === sd_image_fw_norm($start)) {
        return true;
    }
    if (sd_image_fw_parse($fw) === null || sd_image_fw_parse($start) === null) {
        return false;
    }
    return sd_image_fw_cmp($fw, $start) >= 0;
}

/**
 * version FW ของเครื่องอยู่ในรายการที่ชุด image นี้ใช้หรือไม่
 *
 * @param string   $fw
 * @param string[] $list
 * @return bool
 */
function sd_image_fw_in_list(string $fw, array $list): bool
{
    $fw = trim($fw);
    if ($fw === '' || !$list) {
        return false;
    }
    $norm = sd_image_fw_norm($fw);
    foreach ($list as $one) {
        if ($norm === sd_image_fw_norm((string) $one)) {
            return true;
        }
    }
    return false;
}

/**
 * version FW ที่มีในทะเบียน Plus / M / S ไว้เป็นตัวเลือกในฟอร์ม
 *
 * @return string[]
 */
function sd_image_known_fw_versions(): array
{
    $out = [];
    $names = array_keys(sd_image_product_slugs());
    if (!$names) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($names), '?'));
    $res = qr(
        "SELECT DISTINCT a.current_fw_version fw
         FROM assets a JOIN products p ON p.id = a.product_id
         WHERE p.name IN ($ph) AND a.current_fw_version IS NOT NULL AND TRIM(a.current_fw_version) <> ''",
        str_repeat('s', count($names)),
        $names
    );
    while ($res && ($x = $res->fetch_assoc())) {
        $fw = sd_image_sanitize_fw((string) $x['fw']);
        if ($fw !== '') {
            $out[$fw] = $fw;
        }
    }
    $list = array_values($out);
    usort($list, static function ($a, $b) {
        $pa = sd_image_fw_parse($a);
        $pb = sd_image_fw_parse($b);
        if ($pa !== null && $pb !== null) {
            return sd_image_fw_cmp($a, $b);
        }
        if ($pa !== null) {
            return -1;
        }
        if ($pb !== null) {
            return 1;
        }
        return strcasecmp($a, $b);
    });
    return $list;
}

/**
 * ชื่อรุ่นในทะเบียน → กลุ่ม image
 *
 * @return array<string,string> ชื่อสินค้า => plus_m|s
 */
function sd_image_product_slugs(): array
{
    return [
        'bitVisitor Plus' => 'plus_m',
        'bitVisitor M'    => 'plus_m',
        'bitVisitor S'    => 's',
    ];
}

/**
 * product_id ของรุ่นที่ตาม image
 *
 * @return array<int,string> id => plus_m|s
 */
function sd_image_product_id_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $names = array_keys(sd_image_product_slugs());
    $ph = implode(',', array_fill(0, count($names), '?'));
    $res = qr('SELECT id, name FROM products WHERE name IN (' . $ph . ')', str_repeat('s', count($names)), $names);
    $slugs = sd_image_product_slugs();
    while ($res && ($x = $res->fetch_assoc())) {
        $n = (string) $x['name'];
        if (isset($slugs[$n])) {
            $map[(int) $x['id']] = $slugs[$n];
        }
    }
    return $map;
}

/**
 * @param int $productId
 * @return string plus_m|s|''
 */
function sd_image_slug_for_product(int $productId): string
{
    $map = sd_image_product_id_map();
    return $map[$productId] ?? '';
}

/**
 * แปลงค่าที่กรอกเป็น Pi2 / Pi3
 *
 * @param mixed $raw
 * @return string Pi2|Pi3|''
 */
function sd_image_norm_pi($raw): string
{
    $t = strtoupper(preg_replace('/\s+/', '', (string) $raw));
    if ($t === 'PI2' || $t === 'RASPBERRYPI2' || $t === '2') {
        return 'Pi2';
    }
    if ($t === 'PI3' || $t === 'RASPBERRYPI3' || $t === '3') {
        return 'Pi3';
    }
    return '';
}

/**
 * ช่องไฟล์จากกลุ่มรุ่น + บอร์ด
 *
 * @param string $slug plus_m|s
 * @param string $pi   Pi2|Pi3|''
 * @return string
 */
function sd_image_slot(string $slug, string $pi): string
{
    if ($slug === 's') {
        return 's_pi3';
    }
    if ($slug === 'plus_m' && $pi === 'Pi2') {
        return 'plus_m_pi2';
    }
    if ($slug === 'plus_m' && $pi === 'Pi3') {
        return 'plus_m_pi3';
    }
    return '';
}

/**
 * ชื่อไฟล์ที่ต้องใช้
 *
 * @param string $slot
 * @return string
 */
function sd_image_file_for_slot(string $slot): string
{
    $files = sd_image_files();
    return $files[$slot] ?? '';
}

/**
 * S/N เครื่องแรกที่เริ่มใช้ Pi3 — อ้างอิงคร่าว ๆ จากช่วงผลิต ไม่ใช่การยืนยันทีละเครื่อง
 *
 * @return array<string,string> ชื่อรุ่น => รหัสเครื่องจุดตัด
 */
function sd_image_pi3_sn_cutoffs(): array
{
    return [
        'bitVisitor Plus' => 'BP21070995',
        'bitVisitor M'    => 'BM21070821',
    ];
}

/**
 * เลขใน S/N หลังตัวอักษรนำหน้า ใช้เทียบช่วง Pi2/Pi3
 *
 * @param string $code
 * @return string
 */
function sd_image_sn_digits(string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (preg_match('/^[A-Z]+(\d+)$/', $code, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * เดารุ่นบอร์ดจาก S/N ตามจุดตัด Plus/M — ถ้าน้อยกว่าจุดตัดเป็น Pi2 จากนั้นเป็น Pi3
 *
 * @param string $code
 * @param string $productName
 * @return string Pi2|Pi3|''
 */
function sd_image_guess_pi_from_sn(string $code, string $productName): string
{
    $cutoffs = sd_image_pi3_sn_cutoffs();
    if (!isset($cutoffs[$productName])) {
        return '';
    }
    $sn = sd_image_sn_digits($code);
    $cut = sd_image_sn_digits($cutoffs[$productName]);
    if ($sn === '' || $cut === '') {
        return '';
    }
    $len = max(strlen($sn), strlen($cut));
    $snP = str_pad($sn, $len, '0', STR_PAD_LEFT);
    $cutP = str_pad($cut, $len, '0', STR_PAD_LEFT);
    return $snP >= $cutP ? 'Pi3' : 'Pi2';
}

/**
 * ตัดวันที่เป็น Y-m-d
 *
 * @param mixed $raw
 * @return string
 */
function sd_image_ymd($raw): string
{
    $t = substr(trim((string) $raw), 0, 10);
    if ($t === '' || $t === '0000-00-00') {
        return '';
    }
    return sd_image_sanitize_date($t);
}

/**
 * วันที่นี้ถึงหรือหลังวันเริ่มชุดหรือไม่
 *
 * @param string $day
 * @param string $since
 * @return bool
 */
function sd_image_on_or_after(string $day, string $since): bool
{
    return $day !== '' && $since !== '' && strcmp($day, $since) >= 0;
}

/**
 * ข้อความรายการอะไหล่มี SD Card หรือไม่ — ไม่ใช้คำว่า SD ล้วน ๆ
 *
 * @param string $text
 * @return bool
 */
function sd_image_text_has_sd_card(string $text): bool
{
    if ($text === '') {
        return false;
    }
    foreach (['SD Card', 'SD-card', 'SD-Card', 'SDcard', 'Micro SD', 'MicroSD'] as $p) {
        if (mb_stripos($text, $p) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * วัน MA ล่าสุดที่เปลี่ยน SD Card ของแต่ละเครื่อง
 *
 * @param int[] $assetIds
 * @return array<int,string> asset_id => Y-m-d
 */
function sd_image_ma_sd_dates_map(array $assetIds): array
{
    $out = [];
    $ids = [];
    foreach ($assetIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return $out;
    }
    $pats = ['SD Card', 'SD-card', 'SDcard', 'Micro SD'];
    foreach (array_chunk(array_values($ids), 400) as $chunk) {
        $conds = [];
        $types = str_repeat('i', count($chunk));
        $params = $chunk;
        foreach ($pats as $p) {
            $conds[] = 'replace_items LIKE ?';
            $conds[] = "JSON_UNQUOTE(JSON_EXTRACT(versions_json, '$.Replace')) LIKE ?";
            $types .= 'ss';
            $params[] = '%' . $p . '%';
            $params[] = '%' . $p . '%';
        }
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $res = qr(
            "SELECT asset_id, MAX(visited_at) d FROM ma_records
             WHERE asset_id IN ($ph) AND (" . implode(' OR ', $conds) . ")
             GROUP BY asset_id",
            $types,
            $params
        );
        while ($res && ($x = $res->fetch_assoc())) {
            $d = sd_image_ymd($x['d'] ?? '');
            if ($d !== '') {
                $out[(int) $x['asset_id']] = $d;
            }
        }
    }
    return $out;
}

/**
 * วันซ่อมเปลี่ยนอะไหล่พื้นฐาน / SD Card จากระบบ maintenance
 *
 * @param array<int,string> $codes asset_id => asset_code
 * @return array<int,string> asset_id => Y-m-d
 */
function sd_image_repair_sd_dates_map(array $codes): array
{
    $out = [];
    if (!$codes) {
        return $out;
    }
    require_once __DIR__ . '/maintenance_repair_bridge.php';
    if (!function_exists('asset_maintenance_sd_card_dates_map')) {
        return $out;
    }
    $serials = [];
    foreach ($codes as $code) {
        $code = trim((string) $code);
        if ($code !== '') {
            $serials[] = $code;
        }
    }
    try {
        $bySn = asset_maintenance_sd_card_dates_map($serials);
    } catch (Throwable $e) {
        error_log('[sd_image] repair lookup: ' . $e->getMessage());
        return $out;
    }
    if (!$bySn) {
        return $out;
    }
    foreach ($codes as $aid => $code) {
        $k = strtoupper(trim((string) $code));
        if ($k !== '' && !empty($bySn[$k])) {
            $out[(int) $aid] = $bySn[$k];
        }
    }
    return $out;
}

/**
 * สัญญาณคร่าว ๆ ต่อเครื่อง สำหรับเดาชุด image
 *
 * @param array<int,array{id?:int,asset_code?:string,produced_at?:string,current_fw_version?:string}> $rows
 * @return array<int,array{produced:string,fw:string,ma_sd:string,repair:string}>
 */
function sd_image_hints_map(array $rows): array
{
    $out = [];
    $ids = [];
    $codes = [];
    foreach ($rows as $row) {
        $aid = (int) ($row['id'] ?? $row['asset_id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $ids[] = $aid;
        $codes[$aid] = (string) ($row['asset_code'] ?? '');
        $out[$aid] = [
            'produced' => sd_image_ymd($row['produced_at'] ?? ''),
            'fw'       => trim((string) ($row['current_fw_version'] ?? '')),
            'ma_sd'    => '',
            'repair'   => '',
        ];
    }
    if (!$ids) {
        return $out;
    }
    foreach (sd_image_ma_sd_dates_map($ids) as $aid => $d) {
        if (isset($out[$aid])) {
            $out[$aid]['ma_sd'] = $d;
        }
    }
    foreach (sd_image_repair_sd_dates_map($codes) as $aid => $d) {
        if (isset($out[$aid])) {
            $out[$aid]['repair'] = $d;
        }
    }
    return $out;
}

/**
 * คำอธิบายแหล่งที่เดาชุด image
 *
 * @param string $source
 * @param array{produced?:string,fw?:string,ma_sd?:string,repair?:string} $hints
 * @return string
 */
function sd_image_source_reason(string $source, array $hints): string
{
    $d = static function ($v) {
        $v = sd_image_ymd((string) $v);
        if ($v === '' || !function_exists('dthai')) {
            return $v;
        }
        return dthai($v);
    };
    if ($source === 'confirmed') {
        return 'เขียนชุดปัจจุบันแล้ว';
    }
    if ($source === 'ma_sd') {
        return 'น่าจะเป็นชุดปัจจุบัน — จาก MA เปลี่ยน SD Card'
            . (($hints['ma_sd'] ?? '') !== '' ? ' ' . $d($hints['ma_sd']) : '');
    }
    if ($source === 'repair') {
        return 'น่าจะเป็นชุดปัจจุบัน — จากงานซ่อมเปลี่ยนอะไหล่พื้นฐาน'
            . (($hints['repair'] ?? '') !== '' ? ' ' . $d($hints['repair']) : '');
    }
    if ($source === 'produced') {
        return 'น่าจะเป็นชุดปัจจุบัน — จากวันที่ผลิต'
            . (($hints['produced'] ?? '') !== '' ? ' ' . $d($hints['produced']) : '');
    }
    if ($source === 'fw') {
        $start = trim((string) ($hints['fw_start'] ?? ''));
        return 'น่าจะเป็นชุดปัจจุบัน — จาก version FW'
            . (($hints['fw'] ?? '') !== '' ? ' ' . $hints['fw'] : '')
            . ($start !== '' ? ' (ชุดนี้เริ่มที่ ' . $start . ')' : '');
    }
    if ($source === 'fw_old') {
        $old = trim((string) ($hints['matched_file'] ?? ''));
        return 'น่าจะเป็นไฟล์เก่า'
            . ($old !== '' ? ' ' . $old : '')
            . (($hints['fw'] ?? '') !== '' ? ' — จาก version FW ' . $hints['fw'] : '');
    }
    return '';
}

// ────────────────────────────────────────────────────────────────────────────────
// per-asset
// ────────────────────────────────────────────────────────────────────────────────

/**
 * อ่าน Raspberry / SD Image ของเครื่อง
 *
 * @param int $assetId
 * @return array{raspberry:string, file:string}
 */
function sd_image_components(int $assetId): array
{
    $map = sd_image_components_map([$assetId]);
    return $map[$assetId] ?? ['raspberry' => '', 'file' => ''];
}

/**
 * อ่าน Raspberry / SD Image ทีละชุด กัน query ซ้ำตอนสร้างคิว
 *
 * @param int[] $assetIds
 * @return array<int, array{raspberry:string, file:string}>
 */
function sd_image_components_map(array $assetIds): array
{
    $out = [];
    $ids = [];
    foreach ($assetIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return $out;
    }
    foreach (array_chunk(array_values($ids), 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('i', count($chunk)) . 'ss';
        $params = array_merge($chunk, [SD_IMAGE_RASPBERRY_KEY, SD_IMAGE_FILE_KEY]);
        $res = qr(
            "SELECT asset_id, component_name, component_value FROM asset_components
             WHERE asset_id IN ($ph) AND component_name IN (?, ?)",
            $types,
            $params
        );
        while ($res && ($x = $res->fetch_assoc())) {
            $aid = (int) $x['asset_id'];
            if (!isset($out[$aid])) {
                $out[$aid] = ['raspberry' => '', 'file' => ''];
            }
            if ($x['component_name'] === SD_IMAGE_RASPBERRY_KEY) {
                $out[$aid]['raspberry'] = sd_image_norm_pi($x['component_value']);
            } elseif ($x['component_name'] === SD_IMAGE_FILE_KEY) {
                $out[$aid]['file'] = trim((string) $x['component_value']);
            }
        }
    }
    return $out;
}

/**
 * บันทึกค่าชิ้นส่วน (upsert)
 *
 * @param int    $assetId
 * @param string $name
 * @param string $value
 * @return void
 */
function sd_image_upsert_component(int $assetId, string $name, string $value): void
{
    $assetId = (int) $assetId;
    $name = mb_substr(trim($name), 0, 100);
    $value = mb_substr(trim($value), 0, 255);
    if ($assetId <= 0 || $name === '' || $value === '') {
        return;
    }
    q(
        'INSERT INTO asset_components (asset_id, component_name, component_value) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE component_value = VALUES(component_value)',
        'iss',
        [$assetId, $name, $value]
    );
}

/**
 * สถานะ image ของเครื่องหนึ่งตัว — ใช้ทั้งคิวและฟอร์ม MA
 *
 * @param array{id?:int,product_id?:int,asset_code?:string,status?:string,produced_at?:string,current_fw_version?:string} $asset
 * @param string $productName ถ้ารู้ชื่อรุ่นแล้วไม่ต้อง query ซ้ำ
 * @param array{raspberry?:string, file?:string}|null $comp ค่าที่โหลดมาแล้ว ถ้ามี
 * @param array{produced?:string,fw?:string,ma_sd?:string,repair?:string}|null $hints วันที่อ้างอิง (เดาชุดใช้ FW)
 * @return array<string,mixed>
 */
function sd_image_info(array $asset, string $productName = '', $comp = null, $hints = null): array
{
    $id = (int) ($asset['id'] ?? 0);
    $pid = (int) ($asset['product_id'] ?? 0);
    $slug = sd_image_slug_for_product($pid);
    if ($productName === '' && $pid > 0) {
        $prRes = qr('SELECT name FROM products WHERE id = ? LIMIT 1', 'i', [$pid]);
        $pr = $prRes ? $prRes->fetch_row() : null;
        $productName = $pr ? (string) $pr[0] : '';
    }
    if (!is_array($comp)) {
        $comp = $id > 0 ? sd_image_components($id) : ['raspberry' => '', 'file' => ''];
    }
    $piConfirmed = sd_image_norm_pi($comp['raspberry'] ?? '');
    $code = (string) ($asset['asset_code'] ?? '');
    $piGuess = $slug === 'plus_m' ? sd_image_guess_pi_from_sn($code, $productName) : '';
    $piSource = '';
    $pi = '';
    if ($slug === 's') {
        $pi = 'Pi3';
        $piSource = $piConfirmed === 'Pi3' ? 'confirmed' : 'model';
    } elseif ($piConfirmed !== '') {
        $pi = $piConfirmed;
        $piSource = 'confirmed';
    } elseif ($piGuess !== '') {
        $piSource = 'guessed';
    }
    $piDisplay = $pi !== '' ? $pi : $piGuess;
    $slotKnown = sd_image_slot($slug, $pi);
    $slotGuess = ($slotKnown === '' && $piGuess !== '') ? sd_image_slot($slug, $piGuess) : '';
    $slot = $slotKnown !== '' ? $slotKnown : $slotGuess;
    $needFile = sd_image_file_for_slot($slot);
    $haveFile = $comp['file'];
    $inCampaign = $slot !== '' && in_array($slot, sd_image_campaign_slots(), true);
    $done = $needFile !== '' && $haveFile === $needFile;
    $needBoard = $slug === 'plus_m' && $pi === '';
    if (!is_array($hints)) {
        $hints = $id > 0
            ? (sd_image_hints_map([$asset + ['id' => $id]])[$id] ?? ['produced' => '', 'fw' => '', 'ma_sd' => '', 'repair' => ''])
            : ['produced' => '', 'fw' => '', 'ma_sd' => '', 'repair' => ''];
    }
    $hints = [
        'produced' => sd_image_ymd($hints['produced'] ?? ($asset['produced_at'] ?? '')),
        'fw'       => trim((string) ($hints['fw'] ?? ($asset['current_fw_version'] ?? ''))),
        'ma_sd'    => sd_image_ymd($hints['ma_sd'] ?? ''),
        'repair'   => sd_image_ymd($hints['repair'] ?? ''),
    ];
    $fwStart = '';
    if ($slot !== '') {
        $starts = sd_image_fw_starts();
        $fwStart = (string) ($starts[$slot] ?? '');
    }
    $matched = $slot !== '' ? sd_image_version_for_fw($slot, $hints['fw']) : ['file' => '', 'fw_start' => ''];
    $matchedFile = (string) ($matched['file'] ?? '');
    $matchedStart = (string) ($matched['fw_start'] ?? '');
    $hints['fw_start'] = $fwStart;
    $hints['matched_file'] = $matchedFile;
    $hints['matched_fw_start'] = $matchedStart;
    $fileSource = '';
    $inferred = false;
    if ($done) {
        $fileSource = 'confirmed';
    } elseif ($matchedFile !== '' && $matchedFile === $needFile) {
        $fileSource = 'fw';
        if ($haveFile === '' && $slot !== 'plus_m_pi2' && ($inCampaign || $needBoard || $slug === 's')) {
            $inferred = true;
        }
    } elseif ($matchedFile !== '') {
        $fileSource = 'fw_old';
    }
    $needWrite = $inCampaign && !$done && !$inferred;
    $reason = '';
    if ($slug === '') {
        $reason = '';
    } elseif ($done) {
        $reason = 'เขียนชุดปัจจุบันแล้ว';
    } elseif ($inferred) {
        $reason = sd_image_source_reason($fileSource, $hints);
    } elseif ($fileSource === 'fw_old') {
        $reason = sd_image_source_reason('fw_old', $hints);
        if ($needWrite && $needFile !== '') {
            $reason .= ' — ยังต้องเขียน ' . $needFile;
        }
    } elseif ($needBoard && !$needWrite) {
        $reason = 'ต้องดูบอร์ดว่าเป็น Pi2 หรือ Pi3';
    } elseif ($needWrite) {
        $reason = 'ยังไม่ได้เขียนชุด ' . ($needFile !== '' ? $needFile : 'ปัจจุบัน');
    } elseif ($slot === 'plus_m_pi2') {
        $reason = 'Pi2 ใช้ไฟล์ชุดเดิม ไม่ต้องเขียนรอบนี้';
    }
    $cutoffs = sd_image_pi3_sn_cutoffs();
    return [
        'tracked'      => $slug !== '',
        'asset_id'     => $id,
        'asset_code'   => $code,
        'status'       => (string) ($asset['status'] ?? ''),
        'product_id'   => $pid,
        'product_name' => $productName,
        'slug'         => $slug,
        'raspberry'    => $pi,
        'pi_guess'     => $piGuess,
        'pi_source'    => $piSource,
        'pi_display'   => $piDisplay,
        'pi3_cutoff'   => $cutoffs[$productName] ?? '',
        'need_board'   => $needBoard,
        'slot'         => $slot,
        'need_file'    => $needFile,
        'have_file'    => $haveFile,
        'in_campaign'  => $inCampaign,
        'need_write'   => $needWrite,
        'done'         => $done,
        'inferred'     => $inferred,
        'file_source'  => $fileSource,
        'fw_start'     => $fwStart,
        'matched_file' => $matchedFile,
        'matched_fw_start' => $matchedStart,
        'hints'        => $hints,
        'reason'       => $reason,
        'queue'        => ($needBoard && !$inferred) || $needWrite,
    ];
}

/**
 * ตรวจฟอร์ม MA ก่อนบันทึก — ห้ามติ๊กเขียนถ้า Plus/M ยังไม่เลือกบอร์ด
 *
 * @param int   $productId
 * @param array $post
 * @return string ข้อความผิด หรือ '' ถ้าผ่าน
 */
function sd_image_validate_post(int $productId, array $post): string
{
    $slug = sd_image_slug_for_product($productId);
    if ($slug === '') {
        return '';
    }
    $pi = $slug === 's' ? 'Pi3' : sd_image_norm_pi($post['sd_raspberry'] ?? '');
    if (!empty($post['sd_image_write']) && $slug === 'plus_m' && $pi === '') {
        return 'เลือกก่อนว่าเครื่องนี้เป็น Pi2 หรือ Pi3';
    }
    return '';
}

/**
 * payload ให้ฟอร์ม MA วาดแผงเลือกบอร์ด แล้วเทียบไฟล์จาก version FW ในฟอร์ม
 *
 * @param array<string,mixed> $info จาก sd_image_info()
 * @return array<string,mixed>
 */
function sd_image_ajax_payload(array $info): array
{
    if (empty($info['tracked'])) {
        return ['tracked' => false];
    }
    $slug = (string) ($info['slug'] ?? '');
    $files = sd_image_files();
    $allVer = sd_image_versions();
    $piFiles = $slug === 's'
        ? ['Pi3' => $files['s_pi3']]
        : ['Pi2' => $files['plus_m_pi2'], 'Pi3' => $files['plus_m_pi3']];
    $piVers = $slug === 's'
        ? ['Pi3' => $allVer['s_pi3'] ?? []]
        : ['Pi2' => $allVer['plus_m_pi2'] ?? [], 'Pi3' => $allVer['plus_m_pi3'] ?? []];
    return [
        'tracked'      => true,
        'slug'         => $slug,
        'raspberry'    => (string) ($info['raspberry'] ?? ''),
        'pi_guess'     => (string) ($info['pi_guess'] ?? ''),
        'pi_source'    => (string) ($info['pi_source'] ?? ''),
        'pi3_cutoff'   => (string) ($info['pi3_cutoff'] ?? ''),
        'need_board'   => !empty($info['need_board']),
        'need_write'   => !empty($info['need_write']),
        'done'         => !empty($info['done']),
        'inferred'     => !empty($info['inferred']),
        'have_file'    => (string) ($info['have_file'] ?? ''),
        'need_file'    => (string) ($info['need_file'] ?? ''),
        'matched_file' => (string) ($info['matched_file'] ?? ''),
        'file_source'  => (string) ($info['file_source'] ?? ''),
        'reason'       => (string) ($info['reason'] ?? ''),
        'hints_html'   => sd_image_hint_list_html($info, ['hide_fw' => true]),
        'files'        => $piFiles,
        'versions'     => $piVers,
        'campaign_pis' => ['Pi3'],
    ];
}

/**
 * รับค่าจากฟอร์ม MA — บันทึก Pi และ/หรือชื่อไฟล์ที่เขียน
 * ชื่อไฟล์ไม่รับจาก POST ใช้ตาม catalog ของบอร์ดที่เลือก
 * เขียนเมื่อ: ติ๊ก (ฟอร์มแก้ไขเครื่อง) · เปลี่ยน SD Card · หรือ version FW ถึงจุดเริ่มชุดปัจจุบัน
 *
 * @param int   $assetId
 * @param int   $productId
 * @param array $post
 * @return array{ok:bool, message:string}
 */
function sd_image_apply_post(int $assetId, int $productId, array $post): array
{
    $slug = sd_image_slug_for_product($productId);
    if ($slug === '' || $assetId <= 0) {
        return ['ok' => true, 'message' => ''];
    }
    $pi = $slug === 's' ? 'Pi3' : sd_image_norm_pi($post['sd_raspberry'] ?? '');
    $write = !empty($post['sd_image_write']);
    $writeFromFw = false;
    $replacedSd = sd_image_text_has_sd_card((string) ($post['replace_items'] ?? ''));
    if ($replacedSd) {
        $write = true;
    }
    // หน้า MA เทียบจาก version FW ที่เลือก — ถึงจุดเริ่มชุดปัจจุบันถือว่าเขียนแล้ว
    if (!$write && $pi !== '') {
        $fwPosted = sd_image_sanitize_fw((string) ($post['fw_version'] ?? ''));
        if ($fwPosted !== '') {
            $slot = sd_image_slot($slug, $pi);
            $need = sd_image_file_for_slot($slot);
            $matched = $slot !== '' ? sd_image_version_for_fw($slot, $fwPosted) : ['file' => ''];
            if ($need !== '' && (string) ($matched['file'] ?? '') === $need) {
                $write = true;
                $writeFromFw = true;
            }
        }
    }
    if ($pi === '' && !$write) {
        return ['ok' => true, 'message' => ''];
    }
    if ($write && $slug === 'plus_m' && $pi === '') {
        return ['ok' => false, 'message' => 'เลือกก่อนว่าเครื่องนี้เป็น Pi2 หรือ Pi3'];
    }
    if ($pi !== '') {
        sd_image_upsert_component($assetId, SD_IMAGE_RASPBERRY_KEY, $pi);
    }
    if ($write) {
        $slot = sd_image_slot($slug, $pi);
        $file = sd_image_file_for_slot($slot);
        if ($file === '') {
            return ['ok' => false, 'message' => 'ไม่ทราบไฟล์ image ของบอร์ดนี้'];
        }
        sd_image_upsert_component($assetId, SD_IMAGE_FILE_KEY, $file);
        if ($replacedSd && empty($post['sd_image_write']) && !$writeFromFw) {
            return ['ok' => true, 'message' => 'บันทึก SD image แล้ว (เปลี่ยน SD Card)'];
        }
        if ($writeFromFw) {
            return ['ok' => true, 'message' => 'บันทึก SD image ตาม version FW'];
        }
        return ['ok' => true, 'message' => 'บันทึก SD image แล้ว'];
    }
    return ['ok' => true, 'message' => $pi !== '' ? 'บันทึกว่าเป็น ' . $pi : ''];
}

/**
 * บันทึก Raspberry จากโปรไฟล์เครื่อง — ถ้ายืนยันบอร์ดคนละตัวกับไฟล์ image เดิม จะลบชื่อไฟล์ทิ้ง
 *
 * @param int    $assetId
 * @param int    $productId
 * @param string $rawPi ค่าจากฟอร์ม Pi2|Pi3
 * @return array{ok:bool, message:string}
 */
function sd_image_set_raspberry(int $assetId, int $productId, string $rawPi): array
{
    $slug = sd_image_slug_for_product($productId);
    if ($assetId <= 0 || $slug === '') {
        return ['ok' => false, 'message' => 'รุ่นนี้ไม่ตาม Raspberry Pi'];
    }
    $pi = $slug === 's' ? 'Pi3' : sd_image_norm_pi($rawPi);
    if ($pi === '') {
        return ['ok' => false, 'message' => 'เลือก Pi2 หรือ Pi3'];
    }
    sd_image_upsert_component($assetId, SD_IMAGE_RASPBERRY_KEY, $pi);
    $have = sd_image_components($assetId);
    $need = sd_image_file_for_slot(sd_image_slot($slug, $pi));
    if ($have['file'] !== '' && $need !== '' && $have['file'] !== $need) {
        q('DELETE FROM asset_components WHERE asset_id = ? AND component_name = ?', 'is', [$assetId, SD_IMAGE_FILE_KEY]);
    }
    return ['ok' => true, 'message' => 'บันทึกว่าเมนบอร์ดเป็น ' . $pi];
}

/**
 * บันทึกว่าเขียน image ชุดปัจจุบันแล้ว โดยไม่ต้องลง MA
 * ชื่อไฟล์ใช้จาก catalog ตามบอร์ดที่ยืนยัน หรือค่าโดยประมาณจาก S/N
 *
 * @param int    $assetId
 * @param int    $productId
 * @param string $rawPi     Pi2|Pi3 จากฟอร์ม ถ้ามี
 * @param string $assetCode ใช้เดาบอร์ดเมื่อยังไม่ยืนยัน
 * @return array{ok:bool, message:string}
 */
function sd_image_mark_written(int $assetId, int $productId, string $rawPi = '', string $assetCode = ''): array
{
    $slug = sd_image_slug_for_product($productId);
    if ($assetId <= 0 || $slug === '') {
        return ['ok' => false, 'message' => 'รุ่นนี้ไม่ตาม SD image'];
    }
    $info = sd_image_info([
        'id'         => $assetId,
        'product_id' => $productId,
        'asset_code' => $assetCode,
    ]);
    $pi = $slug === 's' ? 'Pi3' : sd_image_norm_pi($rawPi);
    if ($pi === '') {
        $pi = (string) ($info['raspberry'] ?? '');
    }
    if ($pi === '') {
        $pi = (string) ($info['pi_guess'] ?? '');
    }
    if ($pi === '') {
        return ['ok' => false, 'message' => 'เลือก Raspberry Pi ที่แก้ไขเครื่องก่อน แล้วค่อยติ๊กว่าเขียน image แล้ว'];
    }
    sd_image_upsert_component($assetId, SD_IMAGE_RASPBERRY_KEY, $pi);
    $file = sd_image_file_for_slot(sd_image_slot($slug, $pi));
    if ($file === '') {
        return ['ok' => false, 'message' => 'ไม่ทราบไฟล์ image ของบอร์ดนี้'];
    }
    sd_image_upsert_component($assetId, SD_IMAGE_FILE_KEY, $file);
    return ['ok' => true, 'message' => 'บันทึกว่าเขียน ' . $file . ' แล้ว'];
}

/**
 * คิวเครื่องเช่า+สำรองที่ยังต้องดูบอร์ดหรือยังไม่ได้เขียนชุดปัจจุบัน
 *
 * @param string $filter queue|need_write|need_board|guessed|pi2|done|all
 * @param int    $productId 0 = ทุกรุ่นที่ตาม
 * @param string $q
 * @return array{ok:bool, rows:array<int,array<string,mixed>>, counts:array<string,int>}
 */
function sd_image_queue(string $filter = 'queue', int $productId = 0, string $q = ''): array
{
    $out = ['ok' => true, 'rows' => [], 'counts' => ['queue' => 0, 'need_write' => 0, 'need_board' => 0, 'guessed' => 0, 'done' => 0, 'pi2' => 0]];
    $ids = array_keys(sd_image_product_id_map());
    if (!$ids) {
        return $out;
    }
    if ($productId > 0 && !isset(sd_image_product_id_map()[$productId])) {
        return $out;
    }
    $useIds = $productId > 0 ? [$productId] : $ids;
    $ph = implode(',', array_fill(0, count($useIds), '?'));
    $sql = "SELECT a.id, a.asset_code, a.status, a.product_id, a.produced_at, a.current_fw_version, p.name product_name
            FROM assets a
            JOIN products p ON p.id = a.product_id
            WHERE a.product_id IN ($ph) AND a.status IN ('rental', 'spare')";
    $types = str_repeat('i', count($useIds));
    $params = $useIds;
    $q = trim($q);
    if ($q !== '') {
        $sql .= ' AND a.asset_code LIKE ?';
        $types .= 's';
        $params[] = '%' . mb_substr($q, 0, 40) . '%';
    }
    $sql .= ' ORDER BY p.name, a.asset_code';
    $res = qr($sql, $types, $params);
    $raw = [];
    while ($res && ($x = $res->fetch_assoc())) {
        $raw[] = $x;
    }
    $compMap = [];
    $hintMap = [];
    if ($raw) {
        $ids = [];
        foreach ($raw as $x) {
            $ids[] = (int) $x['id'];
        }
        $compMap = sd_image_components_map($ids);
        $hintMap = sd_image_hints_map($raw);
    }
    $all = [];
    foreach ($raw as $x) {
        $aid = (int) $x['id'];
        $info = sd_image_info(
            $x,
            (string) $x['product_name'],
            $compMap[$aid] ?? ['raspberry' => '', 'file' => ''],
            $hintMap[$aid] ?? null
        );
        $bucket = 'done';
        if ($info['need_write']) {
            $bucket = 'need_write';
        } elseif (!empty($info['inferred'])) {
            $bucket = 'guessed';
        } elseif ($info['need_board']) {
            $bucket = 'need_board';
        } elseif ($info['slot'] === 'plus_m_pi2') {
            $bucket = 'pi2';
        }
        $out['counts'][$bucket] = ($out['counts'][$bucket] ?? 0) + 1;
        if ($info['queue']) {
            $out['counts']['queue']++;
        }
        $info['bucket'] = $bucket;
        $all[] = $info;
    }
    $want = $filter === 'all' ? null : $filter;
    foreach ($all as $row) {
        if ($want === 'queue' && !$row['queue']) {
            continue;
        }
        if ($want !== null && $want !== 'queue' && $row['bucket'] !== $want) {
            continue;
        }
        $out['rows'][] = $row;
    }
    return $out;
}

/**
 * จำนวนคิวต่อรุ่น (หน้าเลือกสินค้า MA)
 *
 * @return array<int,int>
 */
function sd_image_queue_counts_by_product(): array
{
    static $n = null;
    if ($n !== null) {
        return $n;
    }
    $n = [];
    $data = sd_image_queue('queue', 0, '');
    foreach ($data['rows'] as $r) {
        $pid = (int) $r['product_id'];
        $n[$pid] = ($n[$pid] ?? 0) + 1;
    }
    return $n;
}

/**
 * แถว Raspberry Pi บนโปรไฟล์เครื่อง — แสดงอย่างเดียว แก้ที่ฟอร์มแก้ไขเครื่อง
 *
 * @param array<string,mixed> $info จาก sd_image_info()
 * @return string HTML ของ dt/dd หรือ '' ถ้ารุ่นนี้ไม่ตาม image
 */
function sd_image_profile_row_html(array $info): string
{
    if (empty($info['tracked']) || !function_exists('asset_head_row_html')) {
        return '';
    }
    $pi = (string) ($info['pi_display'] ?? '');
    $src = (string) ($info['pi_source'] ?? '');
    $val = $pi !== '' ? h($pi) : '<span class="muted">ยังไม่ทราบ</span>';
    if ($src === 'guessed') {
        $cut = (string) ($info['pi3_cutoff'] ?? '');
        $val .= '<div class="muted asset-pi-hint">โดยประมาณจาก S/N'
            . ($cut !== '' ? ' · เริ่มใช้ Pi3 ตั้งแต่ ' . h($cut) : '')
            . '</div>';
    } elseif ($src === 'model') {
        $val .= '<div class="muted asset-pi-hint">รุ่น S เป็น Pi3 อย่างเดียว</div>';
    }
    return asset_head_row_html('Raspberry Pi', $val);
}

/**
 * รายการอ้างอิงคร่าว ๆ ว่าเครื่องน่าจะใช้ image ชุดไหน
 *
 * @param array<string,mixed> $info จาก sd_image_info()
 * @param array{hide_fw?:bool} $opts hide_fw = ไม่โชว์แถว version FW (ฟอร์ม MA เทียบจากช่อง FW อยู่แล้ว)
 * @return string
 */
function sd_image_hint_list_html(array $info, array $opts = []): string
{
    $h = (array) ($info['hints'] ?? []);
    $fwStart = trim((string) ($info['fw_start'] ?? ($h['fw_start'] ?? '')));
    $fwVal = trim((string) ($h['fw'] ?? ''));
    $needFile = (string) ($info['need_file'] ?? '');
    $matchedFile = trim((string) ($info['matched_file'] ?? ($h['matched_file'] ?? '')));
    $fwTxt = $fwVal !== '' ? $fwVal : '—';
    if ($matchedFile !== '') {
        $fwTxt .= ' · ' . ($matchedFile === $needFile ? $matchedFile : 'ไฟล์เก่า ' . $matchedFile);
    } elseif ($fwStart !== '') {
        $fwTxt .= ' · เริ่มที่ ' . $fwStart;
    }
    $fwHitCurrent = $matchedFile !== '' && $needFile !== '' && $matchedFile === $needFile;
    $fmt = static function ($d) {
        $d = sd_image_ymd((string) $d);
        if ($d === '') {
            return '—';
        }
        return function_exists('dthai') ? dthai($d) : $d;
    };
    $rows = [];
    if (empty($opts['hide_fw'])) {
        $rows[] = ['version FW', $fwTxt, $fwHitCurrent];
    }
    $rows[] = ['วันที่ผลิต', $fmt($h['produced'] ?? ''), false];
    $rows[] = ['ซ่อมเปลี่ยนอะไหล่พื้นฐาน', $fmt($h['repair'] ?? ''), false];
    $rows[] = ['MA เปลี่ยน SD Card', $fmt($h['ma_sd'] ?? ''), false];
    $out = '<ul class="asset-sd-hints">';
    foreach ($rows as $row) {
        $out .= '<li>' . h($row[0]) . ' · ' . h($row[1]);
        if ($row[2]) {
            $out .= ' <span class="asset-sd-hit">ถึงชุดปัจจุบัน</span>';
        }
        $out .= '</li>';
    }
    $out .= '</ul>';
    return $out;
}

/**
 * แถว SD image บนโปรไฟล์ — แสดงชื่อไฟล์อย่างเดียว ตัดบรรทัดได้แค่ช่องว่าง ไม่หักที่ขีด
 *
 * @param array<string,mixed> $info จาก sd_image_info()
 * @return string
 */
function sd_image_profile_file_row_html(array $info): string
{
    if (empty($info['tracked']) || !function_exists('asset_head_row_html')) {
        return '';
    }
    $file = sd_image_shown_file($info);
    if ($file === '' && (string) ($info['pi_guess'] ?? '') !== '' && (string) ($info['slug'] ?? '') === 'plus_m'
        && (string) ($info['matched_file'] ?? '') === '') {
        $file = sd_image_file_for_slot(sd_image_slot('plus_m', (string) $info['pi_guess']));
    }
    if ($file === '') {
        $val = '<span class="muted">ยังไม่ทราบ</span>';
    } else {
        $bits = [];
        foreach (preg_split('/\s+/', $file) as $part) {
            if ($part === '') {
                continue;
            }
            $bits[] = '<span class="asset-sd-fn-part">' . h($part) . '</span>';
        }
        $val = '<span class="asset-sd-fn">' . implode(' ', $bits) . '</span>';
        $src = (string) ($info['file_source'] ?? '');
        if ($src === 'fw_old') {
            $val .= '<div class="muted asset-pi-hint">จาก version FW · ยังไม่ใช่ชุดปัจจุบัน</div>';
        } elseif ($src === 'fw' && empty($info['done'])) {
            $val .= '<div class="muted asset-pi-hint">จาก version FW</div>';
        }
    }
    return asset_head_row_html('SD image', $val);
}

/**
 * ช่องติ๊กเขียน image ในฟอร์มแก้ไขเครื่อง วางต่อจาก FW
 *
 * @param array<string,mixed> $assetRow
 * @return string
 */
function sd_image_edit_write_html(array $assetRow): string
{
    $info = sd_image_info($assetRow, (string) ($assetRow['pname'] ?? ''));
    if (empty($info['tracked'])) {
        return '';
    }
    $done = !empty($info['done']);
    $file = (string) ($info['need_file'] ?? '');
    if ($file === '' && (string) ($info['pi_guess'] ?? '') !== '' && (string) ($info['slug'] ?? '') === 'plus_m') {
        $file = sd_image_file_for_slot(sd_image_slot('plus_m', (string) $info['pi_guess']));
    }
    $h = '<label>SD image</label>';
    $h .= '<div class="asset-edit-pi">';
    if ($done) {
        $h .= '<p class="muted ma-field-hint" style="margin:0 0 6px">เขียนชุดปัจจุบันแล้ว: ' . h((string) ($info['have_file'] ?? '')) . '</p>';
        $h .= '<label class="asset-sd-check"><input type="checkbox" checked disabled> เขียน image ชุดปัจจุบันแล้ว</label>';
    } else {
        $h .= '<label class="asset-sd-check"><input type="checkbox" name="sd_image_write" value="1"> เขียน image ชุดปัจจุบันแล้ว (ไม่ต้องลง MA)</label>';
        if (!empty($info['inferred']) || (string) ($info['file_source'] ?? '') === 'fw_old') {
            $h .= '<p class="muted ma-field-hint">' . h((string) ($info['reason'] ?? '')) . ' — ติ๊กเมื่อเขียนชุดปัจจุบันแล้ว</p>';
        } elseif ($file !== '') {
            $h .= '<p class="muted ma-field-hint">ชุดที่ต้องเขียน: ' . h($file) . '</p>';
        } else {
            $h .= '<p class="muted ma-field-hint">เลือก Raspberry Pi ก่อน แล้วค่อยติ๊กช่องนี้</p>';
        }
    }
    $h .= sd_image_hint_list_html($info);
    $h .= '</div>';
    return $h;
}

/**
 * ช่องเลือก Pi2/Pi3 ในฟอร์มแก้ไขเครื่อง — เฉพาะ Plus/M
 *
 * @param array<string,mixed> $assetRow แถวเครื่อง + pname
 * @return string
 */
function sd_image_edit_field_html(array $assetRow): string
{
    $info = sd_image_info($assetRow, (string) ($assetRow['pname'] ?? ''));
    if (empty($info['tracked']) || (string) ($info['slug'] ?? '') !== 'plus_m') {
        return '';
    }
    $cur = (string) ($info['raspberry'] ?? '');
    $h = '<label for="sd_raspberry">Raspberry Pi</label>';
    $h .= '<div class="asset-edit-pi">';
    $h .= '<select name="sd_raspberry" id="sd_raspberry">';
    $h .= '<option value="">ยังไม่ยืนยัน</option>';
    foreach (['Pi2', 'Pi3'] as $opt) {
        $h .= '<option value="' . h($opt) . '"' . ($cur === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
    }
    $h .= '</select>';
    if ((string) ($info['pi_source'] ?? '') !== 'confirmed') {
        $guess = (string) ($info['pi_guess'] ?? '');
        $cut = (string) ($info['pi3_cutoff'] ?? '');
        $h .= '<p class="muted ma-field-hint">หน้าโปรไฟล์เดาไว้'
            . ($guess !== '' ? 'ว่าเป็น ' . h($guess) : '')
            . ($cut !== '' ? ' · เริ่มใช้ Pi3 ตั้งแต่ ' . h($cut) : '')
            . ' — เลือกที่นี่เมื่อยืนยันบอร์ดแล้ว</p>';
    }
    $h .= '</div>';
    return $h;
}
