<?php
/**
 * shared/ui_footer.php — ท้ายเมนูข้าง (คู่มือ · แจ้งปัญหา · เวอร์ชัน) + หน้าต่างแจ้งปัญหา ใช้ทั้งแอปผลิตและแอปอะไหล่
 *
 * ปุ่มแจ้งปัญหาเปิดหน้าต่างเล่าปัญหา + แนบรูป แล้วส่งเข้า LINE ผู้ดูแล (finishgoogs_ma_update/support_report.php)
 * ตัวหน้าต่างและสคริปต์อยู่ที่ finishgoogs_ma_update/assets/app-footer.js · สไตล์อยู่ใน sidebar.css ที่สองแอปโหลดร่วมกัน
 */

/** @var string */
const UI_FOOTER_COMPANY = 'bitCOMBINE';
/** @var string */
const UI_FOOTER_GROUP = 'Production';
/** @var string ชื่อผู้รับแจ้งปัญหาที่แสดงบนหน้าจอ (ตรงกับ SUPPORT_CONTACT_LABEL) */
const UI_FOOTER_CONTACT = 'Sakorn D.';

/**
 * อ่านเลขเวอร์ชัน deploy (YYYY-MM-DD_HHMMSS) เป็นวันที่ไทย + อายุแบบอ่านง่าย
 *
 * @param string $ver
 * @return array{rel:string, full:string}
 */
function ui_footer_version_age(string $ver): array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})/', $ver, $m)) {
        return ['rel' => '', 'full' => ''];
    }
    $ts = mktime((int) $m[4], (int) $m[5], 0, (int) $m[2], (int) $m[3], (int) $m[1]);
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $full = (int) $m[3] . ' ' . $months[(int) $m[2]] . ' ' . ((int) $m[1] + 543) . ' ' . $m[4] . ':' . $m[5];
    $days = (int) floor((strtotime('today') - strtotime(date('Y-m-d', $ts))) / 86400);
    $rel = $days <= 0 ? 'วันนี้' : ($days === 1 ? 'เมื่อวาน' : $days . ' วันก่อน');
    return ['rel' => $rel, 'full' => $full];
}

/**
 * ท้ายเมนูข้าง: คู่มือการใช้งาน · แจ้งปัญหา · บรรทัดเวอร์ชัน
 *
 * เดิมเป็นส่วนท้ายใต้เนื้อหาทุกหน้า (18 ก.ย. 2026) — สูงจนกินพื้นที่รายการ ย้ายมาอยู่ในเมนู
 * มือถือเปิดจากปุ่ม "เมนู" ของแถบล่าง · จอคอมตอนหุบเมนูเหลือแค่ไอคอน
 *
 * @param string $fgBase      URL ฐานแอปผลิต
 * @param string $version     APP_RELEASE_VERSION
 * @param bool   $guideActive หน้าปัจจุบันคือคู่มือ
 * @return string
 */
function ui_sidebar_help_html(string $fgBase, string $version, bool $guideActive = false): string
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $fg = rtrim($fgBase, '/');
    $age = ui_footer_version_age($version);
    $ver = '© ' . $e(UI_FOOTER_COMPANY) . ' · ' . $e(UI_FOOTER_GROUP);
    if ($version !== '') {
        $ver .= '<br><span title="' . $e('รหัสชุด deploy — อัปเดตเมื่อ ' . $age['full']) . '">v' . $e($version)
              . ($age['rel'] !== '' ? ' (' . $e($age['rel']) . ')' : '') . '</span>';
    }
    return '<div class="sidebar-help">'
        . '<a class="nav-cross sidebar-help-link' . ($guideActive ? ' active' : '') . '" href="' . $e($fg . '/guide.php') . '" title="คู่มือการใช้งาน">'
        . '<span class="nav-ico">' . ui_icon_html('book', 18) . '</span><span class="nav-text">คู่มือการใช้งาน</span></a>'
        . '<button type="button" class="nav-cross sidebar-help-link" data-support-open title="แจ้งปัญหา">'
        . '<span class="nav-ico">' . ui_icon_html('alert', 18) . '</span><span class="nav-text">แจ้งปัญหา</span></button>'
        . '<div class="sidebar-ver">' . $ver . '</div>'
        . '</div>';
}

/**
 * หน้าต่างแจ้งปัญหา (เปิดจากปุ่มในเมนูข้าง)
 *
 * @param string $fgBase URL ฐานแอปผลิต (ที่ตั้งของ support_report.php)
 * @param string $csrf   โทเคน csrf ใน session (สองแอปใช้ session เดียวกัน)
 * @return string
 */
function ui_support_modal_html(string $fgBase, string $csrf): string
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $fg = rtrim($fgBase, '/');
    return '<div class="notif-overlay support-overlay" id="support-overlay" hidden>'
        . '<form class="notif-box support-box" id="support-form" data-endpoint="' . $e($fg . '/support_report.php') . '" data-csrf="' . $e($csrf) . '">'
        . '<div class="support-head"><h2>แจ้งปัญหาถึง ' . $e(UI_FOOTER_CONTACT) . '</h2>'
        . '<button type="button" class="support-x" data-support-close aria-label="ปิด">✕</button></div>'
        . '<p class="support-page">หน้า: <span id="support-page-title"></span> <span class="support-muted">(แนบให้อัตโนมัติ)</span></p>'
        . '<textarea name="message" id="support-msg" rows="4" placeholder="เล่าปัญหาที่เจอ เช่น กดปุ่มไหนแล้วเกิดอะไรขึ้น"></textarea>'
        . '<div class="support-imgs" id="support-imgs"></div>'
        . '<label class="support-add-img"><input type="file" id="support-file" accept="image/*" multiple hidden>+ แนบรูป (สูงสุด 4 รูป)</label>'
        . '<p class="support-status" id="support-status" hidden></p>'
        . '<div class="support-actions">'
        . '<button type="button" class="support-btn" data-support-close>ยกเลิก</button>'
        . '<button type="submit" class="support-btn is-primary" id="support-send">ส่งเข้า LINE</button>'
        . '</div>'
        . '</form></div>';
}
