<?php
/**
 * database/tools/line_richmenu_image.php — สร้างรูปริชเมนูไลน์สำรอง (assets/line/richmenu.png)
 *
 * ตำแหน่งปุ่มต้องตรงกับ line_bot_richmenu_def() ใน includes/line_bot.php (บน 2 ปุ่ม · ล่าง 3 ปุ่ม)
 * แก้ข้อความ/สีที่นี่แล้วรันใหม่ จากนั้นกด "ติดตั้งริชเมนู" ในหน้าตั้งค่า LINE
 *
 *   php database/tools/line_richmenu_image.php
 *
 * วาดเป็น HTML แล้วให้ Edge headless ถ่ายภาพ — ได้ฟอนต์ไทยสวยเท่าหน้าเว็บ ไม่ต้องพึ่ง GD
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__, 3) . '/shared/ui_icons.php';

const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
$out = dirname(__DIR__, 2) . '/assets/line/richmenu.png';

// ไอคอนที่ไม่มีในชุด ui_icons — เส้นแบบเดียวกัน (stroke 2)
$extra = [
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    'help'  => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"/>',
    'keyboard' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M6 9h.01M10 9h.01M14 9h.01M18 9h.01M6 13h.01M18 13h.01M10 13h4M7 16h10"/>',
];
$svg = function (string $name) use ($extra) {
    $inner = $extra[$name] ?? null;
    if ($inner === null) {
        return str_replace(['width="16"', 'height="16"'], ['width="230"', 'height="230"'], ui_icon_html($name, 16, 'ic'));
    }
    return '<svg class="ic" width="230" height="230" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
};
// [ไอคอน, ชื่อปุ่ม (คำกริยา = สิ่งที่ต้องทำ), คำอธิบายบรรทัดเดียว, ปุ่มหลัก]
$tiles = [
    ['keyboard', 'พิมพ์ค้นหา',    'S/N · รุ่น · ลูกค้า',        true],
    ['camera',   'สแกนด้วยกล้อง', 'ส่องบาร์โค้ด/QR บนเครื่อง',  true],
    ['box',      'เช็คสต็อก',     'ใหม่ · เช่า · ขาด',          false],
    ['globe',    'เปิดเว็บ',       'ระบบ Production',            false],
    ['help',     'วิธีใช้',         'ดูตัวอย่างการใช้งาน',        false],
];
$cells = '';
foreach ($tiles as [$ic, $label, $sub, $main]) {
    $cells .= '<div class="t' . ($main ? ' m' : '') . '">' . $svg($ic) . '<b>' . htmlspecialchars($label) . '</b>'
        . ($sub !== '' ? '<span>' . htmlspecialchars($sub) . '</span>' : '') . '</div>';
}
$html = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@500;600&display=swap" rel="stylesheet">
<style>
html,body{margin:0;width:2500px;height:1686px;overflow:hidden}
body{display:grid;grid-template-columns:repeat(6,1fr);grid-template-rows:843px 843px;gap:0;background:#3d1f6e;font-family:Prompt,'Leelawadee UI',sans-serif}
.t{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;background:#4b2682;color:#fff;box-shadow:inset 0 0 0 4px #3d1f6e}
.t{grid-column:span 2}.t.m{grid-column:span 3;background:#fff;color:#4b2682}.t.m b{font-size:124px}.t.m span{font-size:64px}
.t.m .ic{color:#e0337f}
.ic{color:#f7b6d2}
b{font-size:108px;font-weight:600;line-height:1}
span{font-size:58px;opacity:.75;line-height:1}
</style></head><body>{$cells}</body></html>
HTML;
$tmp = sys_get_temp_dir() . '/richmenu_' . getmypid() . '.html';
file_put_contents($tmp, $html);
@mkdir(dirname($out), 0777, true);
$cmd = '"' . EDGE . '" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=1'
    . ' --window-size=2500,1686 --virtual-time-budget=4000 --screenshot="' . $out . '" "file:///' . str_replace('\\', '/', $tmp) . '"';
exec($cmd . ' 2>&1', $o, $rc);
@unlink($tmp);
if (!is_file($out)) {
    exit("ถ่ายภาพไม่สำเร็จ (rc=$rc)\n" . implode("\n", $o) . "\n");
}
$sz = getimagesize($out);
echo "ok: $out {$sz[0]}x{$sz[1]} " . round(filesize($out) / 1024) . " KB\n";
