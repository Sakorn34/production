<?php
/**
 * shared/nav_badges.php — ตัวเลขบนเมนูด้านซ้าย (ใช้ทั้งแอปทะเบียนเครื่องและแอปอะไหล่)
 *
 *   ma.php                    เครื่องรอ MA จากระบบเช่า ทุกรุ่นรวมกัน (ยอดเดียวกับหัวหน้าบันทึก MA)
 *   pages/purchase-check.php  อะไหล่ที่ "ควรสั่งแล้ว" (ยอดเดียวกับการ์ดบนหน้าเช็คสั่งซื้อ)
 *
 * เมนูวาดทุกหน้า จึงเก็บผลไว้ในไฟล์ 5 นาทีแล้วใช้ร่วมกันทั้งสองแอป
 * แอปอะไหล่นับ MA เองไม่ได้ (ไม่มีการเชื่อมระบบเช่า) — ใช้ค่าที่แอปทะเบียนเครื่องนับไว้ล่าสุด
 */

const NAV_BADGES_TTL = 300;
/** ค่า MA ที่เก่ากว่านี้ไม่แสดง — แอปอะไหล่รีเฟรชเองไม่ได้ ตัวเลขค้างนานจะพาเข้าใจผิด */
const NAV_BADGES_MAX_AGE = 3600;

function nav_badges_file(): string
{
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'fg_nav_badges.json';
}

/**
 * ตัวเลขบนเมนู — คีย์เป็นชื่อไฟล์ของเมนู
 *
 * @param bool $canCountMa แอปนี้นับเครื่องรอ MA เองได้ไหม (แอปทะเบียนเครื่อง = ได้)
 * @return array<string,array{n:int,title:string}>
 */
function nav_badges(bool $canCountMa): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $file = nav_badges_file();
    $cache = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    $cache = is_array($cache) ? $cache : [];
    $now = time();

    $dirty = false;
    if ($canCountMa && $now - (int) ($cache['ma_t'] ?? 0) >= NAV_BADGES_TTL) {
        $ma = nav_badges_count_ma();
        if ($ma !== null) {
            $cache['ma'] = $ma;
            $cache['ma_t'] = $now;
            $dirty = true;
        }
    }
    if ($now - (int) ($cache['po_t'] ?? 0) >= NAV_BADGES_TTL) {
        $po = nav_badges_count_po();
        if ($po !== null) {
            $cache['po'] = $po['order'];
            $cache['po_near'] = $po['near'];
            $cache['po_t'] = $now;
            $dirty = true;
        }
    }
    if ($dirty) {
        @file_put_contents($file, json_encode($cache), LOCK_EX);
    }

    $out = [];
    if ((int) ($cache['ma'] ?? 0) > 0 && $now - (int) ($cache['ma_t'] ?? 0) < NAV_BADGES_MAX_AGE) {
        $out['ma.php'] = [
            'n'     => (int) $cache['ma'],
            'title' => 'รอ MA ' . number_format((int) $cache['ma']) . ' เครื่อง (ทุกรุ่น จากระบบเช่า)',
        ];
    }
    if ((int) ($cache['po'] ?? 0) > 0) {
        $near = (int) ($cache['po_near'] ?? 0);
        $out['pages/purchase-check.php'] = [
            'n'     => (int) $cache['po'],
            'title' => 'ควรสั่งแล้ว ' . number_format((int) $cache['po']) . ' รายการ (อะไหล่ตามสูตรผลิต)'
                . ($near > 0 ? ' · ใกล้ถึงขั้นต่ำ ' . number_format($near) : ''),
        ];
    }
    return $memo = $out;
}

/** @return int|null null = นับไม่ได้รอบนี้ (คงค่าเดิมไว้) */
function nav_badges_count_ma(): ?int
{
    try {
        if (!function_exists('rent_wait_ma_counts_by_product')) {
            $bridge = dirname(__DIR__) . '/finishgoogs_ma_update/includes/rent_ma_bridge.php';
            if (!is_file($bridge)) {
                return null;
            }
            require_once $bridge;
        }
        $r = rent_wait_ma_counts_by_product();
        return !empty($r['ok']) ? (int) ($r['total_matched'] ?? 0) : null;
    } catch (\Throwable $e) {
        error_log('[nav_badges] MA: ' . $e->getMessage());
        return null;
    }
}

/**
 * ยอดเดียวกับการ์ด "ควรสั่งแล้ว / ใกล้ถึงขั้นต่ำ" บนหน้าเช็คสั่งซื้อ (อะไหล่ตามสูตรผลิตของทุกรุ่น)
 *
 * @return array{order:int,near:int}|null
 */
function nav_badges_count_po(): ?array
{
    try {
        if (!function_exists('purchase_check_load')) {
            require_once dirname(__DIR__) . '/parts/includes/purchase_check.php';
        }
        if (!function_exists('production_db') || !function_exists('stock_status_key')
            || (!function_exists('getDB') && !function_exists('tech_parts_sync_db'))) {
            return null;
        }
        $d = purchase_check_load();
        if (empty($d['ok'])) {
            return null;
        }
        return ['order' => (int) $d['counts']['order'], 'near' => (int) $d['counts']['near']];
    } catch (\Throwable $e) {
        error_log('[nav_badges] PO: ' . $e->getMessage());
        return null;
    }
}

/** ป้ายตัวเลขท้ายเมนู ('' ถ้าไม่มี) */
function nav_badge_html(array $badges, string $file): string
{
    if (!isset($badges[$file])) {
        return '';
    }
    $b = $badges[$file];
    $n = $b['n'] > 999 ? '999+' : number_format($b['n']);
    return '<span class="nav-badge" title="' . htmlspecialchars($b['title'], ENT_QUOTES, 'UTF-8') . '">' . $n . '</span>';
}
