<?php
/**
 * parts/includes/main_theme.php — อ่านธีมจาก site_settings ของระบบ Production
 *
 * ให้ Stock ช่างใช้สี/ฟอนต์เดียวกับ finishgoogs_ma_update (appearance.php)
 */

require_once __DIR__ . '/production_sync.php';

/**
 * โหลด site_settings จาก biton_production (cache ต่อ request)
 *
 * @return array<string,string>
 */
function main_site_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $prod = production_db();
        $st = $prod->query('SELECT skey, sval FROM site_settings');
        while ($row = $st->fetch()) {
            $cache[(string) $row['skey']] = (string) $row['sval'];
        }
    } catch (Throwable $e) {
        error_log('[main_site_settings] ' . $e->getMessage());
    }
    return $cache;
}

/**
 * อ่านค่า setting จากระบบหลัก
 *
 * @param string     $key
 * @param mixed|null $default
 * @return mixed
 */
function main_setting(string $key, $default = null)
{
    $s = main_site_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

/**
 * อ่านสีธีม hex ที่ปลอดภัย
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function main_theme_color(string $key, string $default): string
{
    $v = (string) main_setting($key, $default);
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $default;
}

/**
 * คืนค่า font config ตามระบบหลัก
 *
 * @return array{font:string,google:string,custom_file:string}
 */
function main_font_config(): array
{
    $preset = (string) main_setting('font_preset', 'noto');
    $custom = trim((string) main_setting('font_file', ''));
    if ($custom !== '') {
        return ['font' => "'AppCustomFont', 'Noto Sans Thai', 'Segoe UI', sans-serif", 'google' => '', 'custom_file' => $custom];
    }
    $presets = [
        'system'  => ['font' => "'Noto Sans Thai', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&display=swap'],
        'saraban' => ['font' => "'Sarabun', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap'],
        'prompt'  => ['font' => "'Prompt', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap'],
        'kanit'   => ['font' => "'Kanit', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap'],
        'noto'    => ['font' => "'Noto Sans Thai', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&display=swap'],
    ];
    if (isset($presets[$preset])) {
        return ['font' => $presets[$preset]['font'], 'google' => $presets[$preset]['google'], 'custom_file' => ''];
    }
    return $presets['noto'];
}

/**
 * URL ไฟล์ upload จากระบบหลัก
 *
 * @param string $rel path ใน uploads เช่น fonts/2026/01/x.woff2
 * @return string
 */
function main_upload_url(string $rel): string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    return app_uploads_public_base() . $rel;
}

/**
 * URL โลโก้จากระบบหลัก (relative uploads path)
 *
 * @return string|null
 */
function main_brand_logo_url(): ?string
{
    $logo = trim((string) main_setting('brand_logo', ''));
    if ($logo === '') {
        return null;
    }
    return main_upload_url($logo);
}

/**
 * สีเมนู active — กันค่าขาวล้วน (legacy default) ที่จะกลืนกับตัวอักษรเมนูสีขาว
 *
 * @return string
 */
function parts_sidebar_active_color(): string
{
    $v = main_theme_color('color_sidebar_active', '#e11d74');
    return in_array(strtolower($v), ['#fff', '#ffffff'], true) ? 'rgba(255,255,255,.16)' : $v;
}

/**
 * เปอร์เซ็นต์ขยายขนาดตัวอักษรจาก appearance.php
 *
 * @return int
 */
function main_font_scale_percent(): int
{
    $p = (int) main_setting('font_scale_percent', 100);
    return max(90, min(140, $p));
}

/**
 * CSS variables ขนาดตัวอักษรตาม font_scale_percent
 *
 * @return string
 */
function main_font_css_sizes(): string
{
    $s = main_font_scale_percent() / 100;
    return sprintf(
        '--fs-body:%spx; --fs-h1:%spx; --fs-table:%spx; --fs-badge:%spx;',
        round(15 * $s, 1),
        round(22 * $s, 1),
        round(13.5 * $s, 1),
        round(12 * $s, 1)
    );
}

/**
 * สร้าง CSS variables สำหรับ inject ใน header
 *
 * @return string
 */
function parts_theme_css_block(): string
{
    $fontCfg = main_font_config();
    $logoH = max(20, min(160, (int) main_setting('brand_logo_h', 40)));

    return ':root{
  --primary:' . main_theme_color('color_primary', '#e11d74') . ';
  --primary-dark:' . main_theme_color('color_primary_dark', '#c01862') . ';
  --sidebar-bg:' . main_theme_color('color_sidebar', '#4e2985') . ';
  --sidebar:' . main_theme_color('color_sidebar', '#4e2985') . ';
  --sidebar-active:' . parts_sidebar_active_color() . ';
  --bg:' . main_theme_color('color_page_bg', '#f4f1fb') . ';
  --page-bg:' . main_theme_color('color_page_bg', '#f4f1fb') . ';
  --logo-h:' . $logoH . 'px;
  --app-font:' . $fontCfg['font'] . ';
  --success:#16a34a; --success-soft:#dcfce7;
  --info:#1d4ed8; --info-soft:#dbeafe;
  --warning:#a16207; --warning-soft:#fef9c3;
  --danger:#b91c1c; --danger-soft:#fee2e2;
  --radius:12px; --radius-sm:8px;
  --shadow-sm:0 1px 2px rgba(46,26,90,.07);
  --shadow-md:0 4px 16px rgba(46,26,90,.12);
  --input-h:40px; --transition:.18s ease;
  ' . main_font_css_sizes() . '
}';
}
